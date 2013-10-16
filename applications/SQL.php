<?php
/**
 * SQL Class - container for static methods that generate queries
 * You will need to create your own queries.
 *
 * @author jcrow@daemon.io
 */
class SQL {
	
	/**
	 * buildQueryForRefund() - create an INSERT query for a refund transaction
	 * @param Array $trans_row - DB record of the original transaction
	 * @param String $trans_table - table name that stores information on transactions
	 * @param String $trans_type - RETAIL | RECURRING_BILLING
	 * @return String
	 */
	public static function buildQueryForRefund($trans_row, $trans_table, $trans_type) {
		$ta = $trans_row;
		//Vendor::log(Vendor::LOG_LEVEL_DEBUG, '$ta:'.print_r($ta, true));
		if ($trans_type === 'RETAIL'){
			$query = "INSERT INTO {$trans_table} 
				(terminal_id, trans_dt, 
				user_name, sale_site_id, sx_order_number,
				msg_type, pri_acct_no, cc_last_four, 
				cc_type, processing_code, trans_amount, 
				cc_exp, pos_entry_pin, pos_condition_code,
				avs_response, trans_type)
				VALUES(
				{$ta['terminal_id']}, NOW(),
				'{$ta['user_name']}', '{$ta['sale_site_id']}', '{$ta['sx_order_number']}',
				'0100', '{$ta['pri_acct_no']}', '{$ta['cc_last_four']}', 
				'{$ta['cc_type']}', '200000', {$ta['trans_amount']}, 
				'{$ta['cc_exp']}', '{$ta['pos_entry_pin']}', '{$ta['pos_condition_code']}',
				'{$ta['avs_response']}', 3)";
		} else if ($trans_type === 'RECURRING_BILLING') {
			$query = "INSERT INTO {$trans_table} 
				(batch_id, terminal_id, create_dt, 
				schedule_date, user_name, site_id,
				frozen, pkg_description, uv, 
				msg_type, pri_acct_no, cc_last_four, 
				cc_type, processing_code, trans_amount, 
				cc_exp, pos_entry_pin, pos_condition_code,
				avs_response, trans_type, acquirer_reference_data)
				VALUES(
				{$ta['batch_id']}, {$ta['terminal_id']}, NOW(),
				'{$ta['schedule_date']}', '{$ta['user_name']}', '{$ta['site_id']}',
				'{$ta['frozen']}', '{$ta['pkg_description']}', '{$ta['uv']}',
				'0100', '{$ta['pri_acct_no']}', '{$ta['cc_last_four']}', 
				'{$ta['cc_type']}', '200000', {$ta['trans_amount']}, 
				'{$ta['cc_exp']}', '{$ta['pos_entry_pin']}', '08',
				'{$ta['avs_response']}', 3, 1)";
		}	
		return $query;
	}
	
	/**
	 * Build the query to retrieve the transaction details of the original transaction
	 * @param ISO8583Trans $msg - the ISO8583 message returned from remote vendor
	 * @param String $trans_table - table name that stores information on transactions
	 * @param Bool $duplicate - is this a duplicate transaction. If so change the order by
	 * @return string
	 */
	public static function buildQueryForOriginalTrans($msg, $trans_table, $duplicate = false) {
		// build our query to retrieve the original request data from the DB
		// the retrieval_reference_number value from followup messages will
		// match exactly the first 0100 auth message not the DB id of this transaction
		switch($msg->getMTI(true)) {
			case "0110":
				$msg_type = '0100';
				$id_conditions = "(fdt.id = {$msg->retrieval_reference_number} 
						AND fdt.receipt_number = {$msg->receipt_number})";
				break;
			case "0210":
				$msg_type = '0200';
				break;
			case "0410":
				$msg_type = '0400';
				$id_conditions = "(fdt.id = {$msg->retrieval_reference_number} 
						OR master_trans_id = {$msg->retrieval_reference_number}
						OR fdt.receipt_number = {$msg->receipt_number})";
				break;
			default:
				$msg_type = '%';
				Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Unknown MTI:'.$msg->getMTI(true));
				$id_conditions = "(fdt.id = {$msg->retrieval_reference_number} 
						OR master_trans_id = {$msg->retrieval_reference_number}
						OR fdt.receipt_number = {$msg->receipt_number})";
		}
		
		// order by ASC to get the last submitted trans; order by DESC to get the
		// first. This only applies when two trans match 
		$order_by = 'DESC';
		if ($duplicate) {
			$order_by = 'ASC';
		}
		
		// we may have more than one response with MTI 0410. This is caused by 
		// us sending several timeout reversal transactions and then getting all 
		// responses back at once
		// We violate the spec by sending different bit 12/13 for each 0400 
		// timeout reversal. This is needed to match the response to the correct
		$query = "SELECT fdt.*, ti.terminal_id, mi.mid AS mercant_id
				FROM {$trans_table} fdt
				LEFT JOIN terminal_info ti ON ti.id = fdt.terminal_id
				LEFT JOIN merchant_info mi ON mi.id = ti.merchant_id
				WHERE {$id_conditions}
					AND fdt.msg_type LIKE '{$msg_type}'
					AND ti.terminal_id = {$msg->terminal_id}
					AND (fdt.auth_submit_dt <> '0000-00-000 00:00:00' OR
						fdt.capture_submit_dt <> '0000-00-00 00:00:00')
					ORDER BY auth_submit_dt {$order_by}
					LIMIT 10";
		
		//Vendor::log(Vendor::LOG_LEVEL_DEBUG, 'Query for original trans:'.$query);
		return $query;
	}
	
	/**
	 * buildQueriesForReversal() - create an array of to create a new reversal transaction
	 * @param Array $trans_row - DB record of the query we want to void
	 * @param Int $new_trans_id - the DB id of the reversal transaction
	 * @param RealTimeTrans $app - the calling appInstance
	 * @return array - arracy of queries
	 */
	public static function buildQueriesForReversal($trans_row, $new_trans_id, $app) {
		$queries = array();
		$original_trans_id = $trans_row['id'];
		$queries[] = "UPDATE fd_trans SET capture_submit_dt = NOW() WHERE id ={$original_trans_id}";
		switch($trans_row['cc_type']) {
			case 'VS':
				// Visa queries
				// for 0400 Reversal messages, the amount from the original 0100
				// transaction is the value that needs to be in the first_auth_amount
				// and total_aut_amount fields. If we did incremental auth then
				// we would need to support different values in each.
				$auth_amount = $trans_row['trans_amount'] * 100;
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Visa Reversal auth amount:'.$auth_amount.', trans_amount:'.$trans_row['trans_amount']);
				$queries[] = "INSERT INTO table14_visa (trans_id, aci, issuer_trans_id,
					validation_code, mkt_specific_data_ind, rps, 
					first_auth_amount, total_auth_amount)
					VALUES({$new_trans_id}, '{$trans_row['aci']}', '{$trans_row['issuer_trans_id']}',
					'{$trans_row['validation_code']}', '{$trans_row['mkt_specific_data_ind']}', '{$trans_row['rps']}',
					'{$auth_amount}', '{$auth_amount}')";
				break;
			case 'MC':
				// MasterCard queries
				$auth_amount = $trans_row['trans_amount'] * 100;
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'MC Reversal auth amount:'.$auth_amount.', trans_amount:'.$trans_row['trans_amount']);
				$queries[] = "INSERT INTO table14_mc
					(trans_id, aci, banknet_date,
					banknet_reference, filler, cvc_error_code,
					pos_entry_mode_change, total_auth_amount, addtl_mc_settle_date,
					addtl_banknet_mc_ref)
					VALUES({$new_trans_id}, '{$trans_row['aci']}', '{$trans_row['banknet_date']}',
					'{$trans_row['banknet_reference']}', ' ', '{$trans_row['cvc_error_code']}',
					'{$trans_row['pos_entry_mode_change']}', '{$auth_amount}', '{$trans_row['addtl_mc_settle_date']}',
					'{$trans_row['addtl_banknet_mc_ref']}')";
				break;
			case 'DS':
				// Discover, JCB, Diners, Chain UnionPay queries
				$auth_amount = $trans_row['trans_amount'] * 100;
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Discover Reversal auth amount:'.$auth_amount.', trans_amount:'.$trans_row['trans_amount']);
				$queries[] = "INSERT INTO table14_ds 
					(trans_id, di, issuer_trans_id,
					total_auth_amount)
					VALUES ({$new_trans_id}, '{$trans_row['di']}', '{$trans_row['issuer_trans_id']}',
					'{$auth_amount}')";
				break;
			case 'AX':
				// AmericanExpress queries
				$queries[] = "INSERT INTO table14_amex 
					(trans_id, aei, issuer_trans_id,
					pos_data)
					VALUES('{$new_trans_id}', '{$trans_row['aei']}', '{$trans_row['issuer_trans_id']}',
					'{$trans_row['pos_data']}')";
				break;
			default:
				// Unknown card type
				Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Unknown card type:'.$trans_row['cc_type'].', pri_acct_no:'.$trans_row['pri_acct_no']);
		}
		
		// add a query for the reversal log table; do not add for timeout reversal
		//if ($trans_row['trans_type'] === ISO8583Trans::TRANS_TYPE_REVERSAL) {
			$queries[] = "INSERT INTO full_auth_reversal (original_id, reversal_id)
				VALUES({$trans_row['id']}, {$new_trans_id})";
		//}
		
		return $queries;
	}

	/**
	 * buildQueryForReversal() - create a query to reverse the given DB record
	 * @param Array $t - DB record of the transaction to reverse
	 * @param Int $type - named const from ISO8583Trans class (5 or 6 are the only valid types)
	 * @param String $trans_table - table name that stores information on transactions
	 * @return String
	 */
	public static function buildQueryForReversal($t, $type, $trans_table){
		// 2013-04-17 processing_code value is in question
		// Eileen from FD stated in her email from 4-16 that for full auth reversal
		// the value should be 009000. The spec lists that as Debit reversal
		// Credit reversal is listed as 000000
		// The Receipt Number on a 0400 reversal should match the 0100 message
		// The bit12 (local time) on a REVERSAL should match the original auth
		$q = "INSERT INTO {$trans_table} 
			(trans_type, terminal_id, customer_site_id,
			user_name, sale_site_id, sx_order_number,
			msg_type, pri_acct_no, cc_last_four,
			cc_type, processing_code, trans_amount,
			receipt_number, master_trans_id, 
			trans_dt, cc_exp, pos_entry_pin, 
			auth_iden_response, response_code, avs_response,
			avs_data, response_text, table49_response,
			acquirer_reference_data, pos_condition_code)
			VALUES({$type},(SELECT id FROM terminal_info WHERE terminal_id = '{$t['terminal_id']}'), '{$t['customer_site_id']}',
			'{$t['user_name']}', '{$t['sale_site_id']}', '{$t['sx_order_number']}',
			'0400', '{$t['pri_acct_no']}', '{$t['cc_last_four']}',
			'{$t['cc_type']}', '000000', '{$t['trans_amount']}',
			'{$t['receipt_number']}', {$t['id']},
			'{$t['trans_dt']}', '{$t['cc_exp']}', '{$t['pos_entry_pin']}',  
			'{$t['auth_iden_response']}', '{$t['response_code']}', '{$t['avs_response']}',
			'{$t['avs_data']}', '{$t['response_text']}', '{$t['table49_response']}',
			'{$t['acquirer_reference_data']}', '{$t['pos_condition_code']}')
			";
		return $q;
	}
	/**
	 * buildQueryForTimeoutReversal() - insert a record into the DB that is a
	 * reversal of a previous message
	 * @param Array $t - DB Record for the transaction to reverse
	 * @param type $trans_table - the DB table that stores transaction data
	 * @return String
	 */
	public static function buildQueryForTimeoutReversal($t, $trans_table){
		$q = "INSERT INTO {$trans_table}
			(trans_type, terminal_id, customer_site_id,
			user_name, sale_site_id, sx_order_number,
			msg_type, pri_acct_no, cc_last_four,
			cc_type, processing_code, trans_amount,
			receipt_number,
			trans_dt, cc_exp, pos_entry_pin, r
			auth_iden_response, response_code, avs_response,
			avs_data, response_text, table49_response)
			VALUES(5,(SELECT id FROM terminal_info WHERE terminal_id = '{$t['terminal_id']}'), '{$t['customer_site_id']}',
			'{$t['user_name']}', '{$t['sale_site_id']}', '{$t['sx_order_number']}',
			'0400', '{$t['pri_acct_no']}', '{$t['cc_last_four']}',
			'{$t['cc_type']}', '009000', '{$t['trans_amount']}',
			'{$t['receipt_number']}',
			'{$t['trans_dt']}', '{$t['cc_exp']}', '{$t['pos_entry_pin']}',
			'{$t['auth_iden_response']}', '{$t['response_code']}', '{$t['avs_response']}',
			'4021', '{$t['response_text']}', '{$t['table49_response']}')
			";
		return $q;
	}
	
	/**
	 * getSubmitDraftSQL() - return the SQL query used to retrieve the batch
	 * transactions to submit
	 * @var String (YYYY-MM-DD) - the draft date to pull transactions for
	 * @var Bool $count_only - should we return just the count of transactions 
	 *	rather than the actual transaction details
	 * @return String
	 */
	public static function buildSubmitDraftSQL($draft_date, $count_only = false) {
		// limit the number of transactions at once to less than the total
		// I have been having major memory consumption issues
		$trans_limit = 10;
		$select_fields = ' fdd.* ';
		if ($count_only) {
			$select_fields = ' COUNT(*) AS trans_count';
		}
		$query = "SELECT {$select_fields}
							FROM fd_draft_data fdd
							LEFT JOIN cc_draft_log cdl ON cdl.id = fdd.batch_id
							WHERE fdd.schedule_date = '{$draft_date}' 
								AND response_code = ''
								AND cdl.approve_user <> ''
								AND cdl.approve_dt <> '0000-00-00 00:00:00'
								AND cdl.approve_ip_address <> ''
								AND fdd.auth_submit_dt = '0000-00-00 00:00:00'
						LIMIT {$trans_limit}";
		return $query;
	}
	
	/**
	 * updateSubmitDTQuery() - create a query to update the submit_dt of a transaction
	 * @param Int $id - the DB record ID of the transaction
	 * @param String $trans_table - the table name that sotres the transaction data
	 * @param Array $tr - DB record for this transaction
	 * @return String
	 */
	public static function updateSubmitDTQuery($id, $trans_table, $tr) {
		$dt = $tr['submit_dt'];
		$sql_fields = '';
		switch ($tr['acquirer_reference_data']) {
			case 0:
				// this is an auth only record
				$sql_fields = "auth_submit_dt = '{$dt}' ";
				break;
			case 1:
				// this is an and and capture record
				$sql_fields = "auth_submit_dt = '{$dt}', capture_submit_dt = '{$dt}' ";
				break;
			case 2:
				// this is a capture only record
				$sql_fields = "capture_submit_dt = '{$tr['capture_submit_dt']}' ";
				break;
		}
				
		$q = "UPDATE {$trans_table} SET {$sql_fields} WHERE id ={$id}";
		return $q;
	}
	
	/**
	 * refundOriginalTransQuery() - create the SQL to pull vlaues required to
	 * create a refund transaction
	 * @param Int $id - DB record id of the transaction to refund
	 * @param String $trans_table - the table name that sotres the transaction data
	 * @return string
	 */
	public static function refundOriginalTransQuery($id, $trans_table){
		$q = "SELECT id, terminal_id, trans_dt,
				user_name, sale_site_id,  msg_type, 
				pri_acct_no, cc_last_four, cc_type, 
				trans_amount, cc_exp, pos_entry_pin, 
				pos_condition_code, response_code, avs_response,
				sx_order_number
			FROM {$trans_table}
			WHERE id=".$id;
		return $q;
	}
	
	/**
	 * singleTransDetailQuery() - query to retrieve all data for a single transaction
	 * @param Int $id - DB record id for the trans to pull
	 * @param String $trans_table - the DB table name to pull transactions from
	 * @return String
	 */
	public static function singleTransDetailQuery($id, $trans_table){
		$q = "SELECT fdt.*,
		CASE
			WHEN t14v.aci IS NOT NULL THEN t14v.aci
			WHEN t14mc.aci IS NOT NULL THEN t14mc.aci
		END AS aci,
		CASE 
			WHEN t14v.issuer_trans_id IS NOT NULL THEN t14v.issuer_trans_id
			WHEN t14ax.issuer_trans_id IS NOT NULL THEN t14ax.issuer_trans_id
			WHEN t14ds.issuer_trans_id IS NOT NULL THEN t14ds.issuer_trans_id
		END AS issuer_trans_id,
		CASE 
			WHEN t14v.total_auth_amount IS NOT NULL THEN t14v.total_auth_amount
			WHEN t14mc.total_auth_amount IS NOT NULL THEN t14mc.total_auth_amount
			WHEN t14ds.total_auth_amount IS NOT NULL THEN t14ds.total_auth_amount
		END AS total_auth_amount,
		t14ax.aei, t14ax.pos_data, t14ax.seller_id, -- end of AmEx fields
		t14ds.di, -- end of Discover fields
		CASE 
			WHEN t14v.mkt_specific_data_ind IS NOT NULL THEN t14v.mkt_specific_data_ind
			WHEN t14mc.mkt_specific_data_ind IS NOT NULL THEN t14mc.mkt_specific_data_ind
		END AS mkt_specific_data_ind,
		CASE
			WHEN t14mc.filler IS NOT NULL THEN t14mc.filler
			WHEN t14ds.filler IS NOT NULL THEN t14ds.filler
			WHEN t14ax.filler IS NOT NULL THEN t14ax.filler
		END AS filler,
		CASE
			WHEN t14mc.filler2 IS NOT NULL THEN t14mc.filler2
			WHEN t14ds.filler2 IS NOT NULL THEN t14ds.filler2
			WHEN t14ax.filler2 IS NOT NULL THEN t14ax.filler2
		END AS filler2,
		t14v.validation_code, t14v.rps, t14v.first_auth_amount,  -- end of Visa fields
		t14mc.banknet_date, t14mc.banknet_reference, t14mc.cvc_error_code, 
			t14mc.pos_entry_mode_change, t14mc.trans_edit_code_error, 
			t14mc.addtl_mc_settle_date, t14mc.addtl_banknet_mc_ref, 
			t14mc.filler3, -- end of MC fields
		fdvc.card_level_response_code, fdvc.source_reason_code, -- end of FD Visa Compliance fields
		fdmcq.TD_card_data_input_cap, fdmcq.TD_cardholder_auth_cap, 
			fdmcq.TD_card_capture_cap, fdmcq.term_oper_environ, 
			fdmcq.cardholder_present_data, fdmcq.card_present_data,
			fdmcq.CD_input_mode, fdmcq.cardholder_auth_method, 
			fdmcq.cardholder_auth_entity, fdmcq.card_data_output_cap, 
			fdmcq.term_data_output_cap, fdmcq.pin_capture_cap, -- end of FD MC Compl fields
		fddsc.processing_code AS ds_processing_code, fddsc.sys_trace_audit_num, 
			fddsc.pos_entry_mode, fddsc.local_tran_time, fddsc.local_tran_date, 
			fddsc.response_code AS ds_response_code, fddsc.pos_data AS ds_pos_data, 
			fddsc.track_data_condition_code, fddsc.avs_result AS ds_avs_result, fddsc.nrid,  -- end of DS complaiance fields
		ttl.type_name,
		mi.merch_cat_code AS merchant_category_code, mi.network_international_id,
		mi.mid AS merchant_id, mi.zip_code AS merchant_zip_code,
		mi.host_capture, -- acquirer_reference_data is now passed straight through and this is the terminal setting
		ti.terminal_id, -- this overwrites the value from the fdt table
		tl.type_name AS terminal_type_name -- one of REATAIL | DIRECT_MARKETING
		
			FROM {$trans_table} fdt
			LEFT JOIN terminal_info ti ON ti.id = fdt.terminal_id
			LEFT JOIN terminal_type_list tl ON tl.id = ti.terminal_type_id
			LEFT JOIN merchant_info mi ON mi.id = ti.merchant_id
			LEFT JOIN trans_type_list ttl ON ttl.type_id = fdt.trans_type
			LEFT JOIN table14_visa t14v ON t14v.trans_id = fdt.id
			LEFT JOIN table14_mc t14mc ON t14mc.trans_id = fdt.id
			LEFT JOIN table14_amex t14ax ON t14ax.trans_id = fdt.id
			LEFT JOIN table14_ds t14ds ON t14ds.trans_id = fdt.id
			LEFT JOIN fd_visa_compliance fdvc ON fdvc.trans_id = fdt.id
			LEFT JOIN fd_mastercard_qualification fdmcq ON fdmcq.trans_id = fdt.id
			LEFT JOIN fd_discover_compliance fddsc ON fddsc.trans_id = fdt.id
						WHERE fdt.id={$id}";
		return $q;
	}
	
	/**
	 * buildQueryForTransUpdate() - Build the queries to update the transaction in the
	 * database when a response is received
	 * @param ISO8583Trans $msg - our object returned from the remote vendor
	 * @param Vendor $app - our Vendor object (extends appInstance)
	 */
	public static function buildQueryForTransUpdate($msg, $app) {
		//Vendor::log(Vendor::LOG_LEVEL_DEBUG, '$msg:'.print_r($msg, true));
		$queries = array();
		// build the main table update query
		// create our update query
		$auth_iden_response = '';
		$response_code = '';
		$avs_response = '';
		$response_text = '';
		$table49_response = '';
		$capture = false;
		
		if ($msg->dataExistsForBit(38)) {
			$auth_iden_response = $msg->getDataForBit(38);
		}

		if ($msg->dataExistsForBit(39)) {
			$response_code = $msg->getDataForBit(39);
			// if we do not have bit38 data, and response_code is '00' and
			// transaction type is not a refund then this is a capture response
			if (strlen($auth_iden_response) === 0 
					&& $response_code === '00'
					&& $msg->_trans_type !== ISO8583Trans::TRANS_TYPE_REFUND) {
				$capture = true;
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Setting response trans('.$msg->original_trans_id.') to capture === true');
			}
			
		}

		if ($msg->dataExistsForBit(44)){
			$avs_response = $msg->getDataForBit(44);
		}
		
		if ($msg->dataExistsForTable(22)) {
			$response_text = $msg->getParsedBit63Table22();
		}
		if ($msg->dataExistsForTable(49)) {
			$capture = false;
			$table49_response = $msg->getParsedBit63Table49();
		}
		//$sql_strings = array($auth_iden_sql, $resp_code_sql, $avs_resp_sql, $resp_text_sql);
		
		
		// form an actual sql string from our data
		$sql_snippet = "auth_iden_response = '{$auth_iden_response}', 
			response_code='{$response_code}',
			avs_response='{$avs_response}', 
			response_text='{$response_text}', 
			table49_response = '{$table49_response}' ";
		if ($capture === true) {
			$sql_snippet = "capture_response_code='{$response_code}'";
		}

		$query = "UPDATE {$app->config->sqltable->value} SET {$sql_snippet}
			WHERE id = {$msg->original_trans_id}";
		$queries[] = $query;
		
		// <editor-fold defaultstate="collapsed" desc="Table14 Card Specific Queries">
		// now create query specific to card type based on table14
		// 0410 Reversal Response Messages already have these tables created
		// CAPTURE (0100) messages already have these created
		if ($msg->getMTI() !== ISO8583Trans::MTI_0410  && $capture !== true) {
			switch($msg->card_type) {
				case 'Visa' :
					if ($msg->dataExistsForTable(14)) {
						$tbl14 = $msg->getParsedBit63Table14();
						if ($msg->dataExistsForBit(39) && $msg->getDataForBit(39) == '00') {
							$tbl14['first_auth_amount'] = $msg->original_trans_amount*100;
							$tbl14['total_auth_amount'] = $msg->original_trans_amount*100;
						} else {
							Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Not changing Visa total_authAmount! Bit39:'.bin2hex($msg->getDataForBit(39, true)));
						}
						$query = "INSERT INTO table14_visa 
							(trans_id, aci, issuer_trans_id, 
							validation_code, mkt_specific_data_ind, rps, 
							first_auth_amount, total_auth_amount)
							VALUES
							({$msg->original_trans_id}, '{$tbl14['aci']}', '{$tbl14['issuer_trans_id']}',
							'{$tbl14['validation_code']}','{$tbl14['mkt_specific_data_ind']}','{$tbl14['rps']}',
							'{$tbl14['first_auth_amount']}','{$tbl14['total_auth_amount']}')";
						$queries[] = $query;
					}
					break;
				case 'Master Card':
					if ($msg->dataExistsForTable(14)) {
						$tbl14 = $msg->getParsedBit63Table14();
						if ($msg->dataExistsForBit(39) && $msg->getDataForBit(39) == '00') {
							//$tbl14['first_auth_amount'] = $msg->getDataForBit(4);
							$tbl14['total_auth_amount'] = $msg->original_trans_amount*100;
						}
						$query = "INSERT INTO table14_mc
							(trans_id, aci, banknet_date,
							banknet_reference, filler, cvc_error_code,
							pos_entry_mode_change, trans_edit_code_error, filler2,
							mkt_specific_data_ind, filler3, total_auth_amount,
							addtl_mc_settle_date, addtl_banknet_mc_ref)
							VALUES
							({$msg->original_trans_id}, '{$tbl14['aci']}', '{$tbl14['banknet_date']}', 
							'{$tbl14['banknet_reference']}', '{$tbl14['filler']}', '{$tbl14['cvc_error_code']}', 
							'{$tbl14['pos_entry_mode_change']}', '{$tbl14['trans_edit_code_error']}', '{$tbl14['filler2']}', 
							'{$tbl14['mkt_specific_data_ind']}', '{$tbl14['filler3']}', '{$tbl14['total_auth_amount']}', 
							'{$tbl14['addtl_mc_settle_date']}', '{$tbl14['addtl_banknet_mc_ref']}')";
						$queries[] = $query;
					}
					break;
				case 'American Express':
					if ($msg->dataExistsForTable(14)) {
						$tbl14 = $msg->getParsedBit63Table14();
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'AmEx Table14 response saved. Table14:'.print_r($tbl14, true));
						$query = "INSERT INTO table14_amex
							(trans_id, aei, issuer_trans_id,
							filler, pos_data, filler2,
							seller_id)
							VALUES
							({$msg->original_trans_id}, '{$tbl14['aei']}', '{$tbl14['issuer_trans_id']}', 
							'{$tbl14['filler']}', '{$tbl14['pos_data']}', '{$tbl14['filler2']}',
							'{$tbl14['seller_id']}')";
						$queries[] = $query;
					}
					break;
				case 'Discover':
				case 'Diners Club':
				case 'JCB':
					if ($msg->dataExistsForTable(14)) {
						$tbl14 = $msg->getParsedBit63Table14();
						if ($msg->dataExistsForBit(39) && $msg->getDataForBit(39) == '00') {
							// FIXME Should this field be zero filled 
							//$tbl14['filler2'] = $msg->original_trans_amount*100;
							Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Changing tbl14 filler2 value to:'.$tbl14['filler2']);
							$tbl14['total_auth_amount'] = $msg->original_trans_amount*100;
							Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Changing tbl14 total_auth_amount value to:'.$tbl14['total_auth_amount']);
						}
						$query = "INSERT INTO table14_ds
							(trans_id, di, issuer_trans_id,
							filler, filler2, total_auth_amount)
							VALUES
							({$msg->original_trans_id}, '{$tbl14['di']}', '{$tbl14['issuer_trans_id']}', 
							'{$tbl14['filler']}', '{$tbl14['filler2']}', '{$tbl14['total_auth_amount']}')";
						$queries[] = $query;
					}
					break;
				default:
					Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'TODO Write query to update card type:'.$msg->card_type);
			}
		}
		//</editor-fold>
		
		//<editor-fold defaultstate="collapsed" desc="Card Compliance/Qualification Tables">
		// we do not want to add a new entry for 0100 CAPTURE responses
		if ($capture !== true) {
			switch($msg->card_type) {
				case 'Visa':
					if ($msg->dataExistsForTable('VI')) {
						$tblVI = $msg->getParsedBit63TableVI();
						$query = "INSERT INTO fd_visa_compliance
							(trans_id, card_level_response_code, source_reason_code,
							`unknown`)
							VALUES
							({$msg->original_trans_id},'{$tblVI['CR']}', '{$tblVI['RS']}',
							'{$tblVI['UF']}')";
						$queries[] = $query;
					}
					break;
				case 'Master Card':
					if ($msg->dataExistsForTable('MC')) {
						$tblMC = $msg->getParsedBit63TableMC();
						$query = "INSERT INTO fd_mastercard_qualification
							(trans_id, TD_card_data_input_cap, TD_cardholder_auth_cap,
							TD_card_capture_cap, term_oper_environ, cardholder_present_data,
							card_present_data, CD_input_mode, cardholder_auth_method,
							cardholder_auth_entity, card_data_output_cap, term_data_output_cap,
							pin_capture_cap)
							VALUES
							({$msg->original_trans_id}, '{$tblMC['card_data_input_cap']}', '{$tblMC['cardholder_auth_cap']}',
							'{$tblMC['card_capture_cap']}', '{$tblMC['term_oper_environ']}', '{$tblMC['cardholder_present']}',
							'{$tblMC['card_present_data']}', '{$tblMC['card_data_input_mode']}', '{$tblMC['cardholder_auth_method']}',
							'{$tblMC['cardholder_auth_entity']}', '{$tblMC['card_data_output_cap']}', '{$tblMC['terminal_data_out_cap']}',
							'{$tblMC['pin_capture_cap']}')";
						$queries[] = $query;
					}
					break;
				case 'Discover':
				case 'Diners Club':
				case 'JCB':
					if ($msg->dataExistsForTable('DS')) {
						$tblDS = $msg->getParsedBit63TableDS();
						$query = "INSERT INTO fd_discover_compliance
							(trans_id, processing_code, sys_trace_audit_num,
							pos_entry_mode, local_tran_time, local_tran_date,
							response_code, pos_data, track_data_condition_code,
							avs_result, nrid)
							VALUES
							($msg->original_trans_id, '{$tblDS['processing_code']}', '{$tblDS['sys_trc_audit_num']}', 
							'{$tblDS['pos_entry_mode']}', '{$tblDS['local_tran_time']}', '{$tblDS['local_tran_date']}', 
							'{$tblDS['response_code']}', '{$tblDS['pos_data']}', '{$tblDS['trk_data_cond']}', 
							'{$tblDS['avs']}', '{$tblDS['nrid']}')";
						$queries[] = $query;
					}
					break;

			}
		}
		//</editor-fold>
		
		// FIXME at this point the $msg object does not have the account number
		//<editor-fold defaultstate="collapsed" desc="AVS Date Log Update">
		if ($msg->dataExistsForBit(44)) {
			$avs_resp = $msg->getDataForBit(44, true);
			$md5_hash = md5($msg->encrypted_acct_no);
			//Vendor::log(Vendor::LOG_LEVEL_DEBUG, "Using {$msg->encrypted_acct_no} to create md5 hash: {$md5_hash}");
			//Vendor::log(Vendor::LOG_LEVEL_DEBUG, '$msg:'.print_r($msg, true));
			$query = "INSERT INTO avs_response_log (acct_number_hash, avs_date, avs_response)
				VALUES('{$md5_hash}', CURDATE(), '{$avs_resp}')";
			$queries[] = $query;
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'No data exists for bit 44. Not creating AVS response log query');
		}
		//</editor-fold>
		
		//Vendor::log(Vendor::LOG_LEVEL_DEBUG, 'Trans update queries:'.print_r($queries, true));
		return $queries;
	}
	
	/**
	 * viewAllTransQuery() - get the list of all transaction using the given
	 * qualifiers
	 * @param Int $min_trans_id - transactions with ID less than this are excluded
	 * @param String $after_date - YYYY-MM-DD transaction create_dt must be after
	 * @param String $trans_table - the table name to pull transaction data from 
	 * @return string
	 */
	public static function viewAllTransQuery($min_trans_id = 0, $after_date = '1969-12-31', $trans_table){
		$q = "SELECT fdt.id, fdt.auth_submit_dt, fdt.capture_submit_dt, fdt.msg_type, fdt.cc_last_four, 
				fdt.cc_type, fdt.processing_code, fdt.trans_amount, fdt.receipt_number, 
				fdt.cc_exp, fdt.pos_entry_pin, fdt.pos_condition_code,
				fdt.auth_iden_response, fdt.response_code, fdt.avs_data, fdt.avs_response,
				fdt.table49_response, fdt.refunded
			FROM {$trans_table} fdt
			WHERE id > {$min_trans_id}
				AND create_dt > '{$after_date}'
			ORDER BY create_dt DESC
			LIMIT 100";
		return $q;
	}
	
	/**
	 * findReversalTransQuery
	 * @param Int $id - DB record ID of the trans to check for reversal
	 * @param String $trans_table - the table name to pull transaction data from 
	 * @return string - the query to execute
	 */
	public static function findReversalTransQuery($id, $trans_table){
		$q = "SELECT fdt.id 
				FROM {$trans_table} fdt
				LEFT JOIN trans_type_list ttl ON ttl.type_id = fdt.trans_type
				WHERE ttl.type_name = 'TIMEOUT_REVERSAL'
					AND fdt.master_trans_id = {$id}";
		return $q;
	}
	
	/**
	 * buildQueryForTransInsert() - create a transaction record from an ISO8583
	 * object
	 * @param ISO8583Trans $iso - the ISO object to build a transaction record from
	 * @param String $trans_table - the DB table that stores transaction data
	 * Aparam Array[] $tr - DB record to pull extra info from (original transaction record)
	 * @return String
	 */
	public static function buildQueryForTransInsert($iso, $trans_table, $tr) {
		// get our individual pieces of infor from the transaction
//		$pri_acct_no = Vendor::decrypt_data($tr['pri_acct_no']);
//		if (is_array($pri_acct_no)) {
//			Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'Failed to decrypt! '.print_r($pri_acct_no, true));
//			return '';
//		}
//		$iso->credit_card = new CreditCard($pri_acct_no);
//		if (substr($pri_acct_no,0,1) === '3') {
//			// AmEx card
//			//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Trans ('.$trans_id.') is an AmEx. Bit2 length:');
//			$pri_acct_no = substr($pri_acct_no,0,-1);
//		}
//		$cc_last_four = $tr['cc_last_four'];
//		$card_type = $tr['cc_type'];
//		$processing_code = $iso->getDataForBit(3, true);
//		$trans_amount = $iso->getDataForBit(4, true);
		try{
			$time = $iso->getDataForBit(12, true); // HHMMSS
			$date = $iso->getDataForBit(13, true); // MMDD
			$auth_submit_dt = '2013-'.substr($date,0,1).'-'.substr($date,-2).' '.substr($time,0,1).':'.substr($time,1,2).':'.substr($time,-2);
		}catch(Exception $e) {
			$auth_submit_dt = '';
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Exception Caught! $e:'.$e);
		}
		$mti = $iso->getMTI(true);
//		$cc_exp = $iso->getDataForBit(14, true);
//		$pos_entry_pin = $iso->getDataForBit(22, true);
//		$pos_condition_code = $iso->getDataForBit(25, true);
//		$trans_id = $iso->getDataForBit(37);
//		$avs_data = $iso->getDataForBit(48, true);
		$q = "INSERT INTO {$trans_table}
			(trans_type, batch_id, terminal_id, create_dt,
			schedule_date, auth_submit_dt, user_name, site_id, 
			frozen, pkg_description, uv, draft_amount,
			tan_tax_amount, msg_type, pri_acct_no, cc_last_four,
			cc_type, processing_code, trans_amount, amount_resp,
			receipt_number, trans_dt, cc_exp, pos_entry_pin,
			pos_condition_code, avs_data)
			VALUES(1,{$tr['batch_id']},(SELECT id FROM terminal_info WHERE terminal_id = '{$tr['terminal_id']}'),NOW(),
			'2013-05-01','{$auth_submit_dt}', '{$tr['user_name']}', '{$tr['site_id']}',
			'{$tr['frozen']}', '{$tr['pkg_description']}', '{$tr['uv']}', {$tr['draft_amount']},
			{$tr['tan_tax_amount']}, '{$tr['msg_type']}', '{$tr['pri_acct_no']}', '{$tr['cc_last_four']}',
			'{$tr['cc_type']}', '{$tr['processing_code']}', '{$tr['trans_amount']}', '',
			'{$tr['receipt_number']}', NOW(), '{$tr['cc_exp']}', '{$tr['pos_entry_pin']}',
			'{$tr['pos_condition_code']}', '{$tr['avs_data']}')
			";
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Query to insert trans: '.$q);
		return $q;
	}
	
	public static function originalTransForRefund($id) {
		$q = 'SELECT id, batch_id, terminal_id, schedule_date,
                                user_name, site_id, frozen,
                                pkg_description, uv, msg_type,
                                pri_acct_no, cc_last_four, cc_type,
                                trans_amount, cc_exp, pos_entry_pin,
                                pos_condition_code, response_code, avs_response
                        FROM fd_draft_data
                        WHERE id ='.$id;
		return $q;

	}
	
	/**
	 * viewNonSettledTrans() - build the query to view non-settled transactions
	 * @param String $trans_table - the DB table name that holds the transactions
	 * @param int $num_trans - how many transactions to view
	 * @return String
	 */
	public static function viewNonSettledTrans($trans_table, $num_trans=1){
		$q = "SELECT fdt.* 
			FROM {$trans_table} fdt
			LEFT JOIN terminal_info ti ON ti.id=fdt.terminal_id
			LEFT JOIN merchant_info mi ON mi.id = ti.merchant_id
			WHERE mi.host_capture IS FALSE
				AND fdt.capture_submit_dt = '0000-00-00 00:00:00'
				AND fdt.auth_submit_dt <> '0000-00-00 00:00:00'
				AND fdt.acquirer_reference_data = 0
				AND fdt.response_code = '00'
				AND fdt.msg_type = '0100'
			LIMIT {$num_trans}";
		return $q;
	}
}

?>
