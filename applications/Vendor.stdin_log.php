<!DOCTYPE html>
<html lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<style type="text/css">
	#pending_trans th{
		border: 1px solid black;
		border-collapse: collapse;
	}
	#pending_trans td{
		border: 1px solid black;
		border-collapse: collapse;
	}
	.border1{
		border: 1px solid black;
		border-collapse: collapse;
	}
	.even{
		/* Styles for the even numbered rows */
	}
	.odd{
		/* Styles for the odd numbered rows */
		background-color: #c0c0c0;
	}
	.highlight{
		/* styles for when the cursor hovers over a row */
		background-color: darkturquoise;
		cursor: pointer;
	}
</style>
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.8.2/jquery.min.js"></script>
<link rel="stylesheet" href="//ajax.googleapis.com/ajax/libs/jqueryui/1.9.0/themes/base/jquery-ui.css" type="text/css" media="all" /> 
<script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.9.0/jquery-ui.min.js"></script>
<script src="/js/jquery.json-2.3.min.js"></script>
<script type="text/javascript" src="/js/Vendor.js"></script>
<title>phpdaemon Example</title>
</head>
<body>
	<div id="content">
		<h2>Vendor Process stdin.log File</h2>
		<?php
		$sqli = new mysqli('127.0.0.1', 'test', 'test', 'double_drafts');
		/**
	 * Bytes that indicate the start of a message in the TCP data
	 * @var String
	 */
		$pkt_start_bytes = "\x02\x46\x44\x02";

		/**
		 * Bytes that indicate the end of a TCP message
		 * @var String
		 */
		$pkt_end_bytes = "\x03\x46\x44\x03";

		/**
		 * Number of bytes to use as the packet size
		 * @var int
		 */
		$pkt_size_bytes = 2;

		/**
		 * Smallest possible packet in bytes. Add pkt_start, pkt_size, & pkt_end bytes
		 * @var Int
		 */
		$min_pkt_size = 10;
		
		$pkt_array = array();
		
		$receipt_nums = array();
		
		// read our buffer from the job
		$buffer = $this->job->getResult('read_buffer');
		echo "We have ".strlen($buffer)." bytes of buffer to process!";
		
		// open log to save packets into
		$out_log = fopen('packets.log', 'w+');
		$reject_log = fopen('packet_rejects.log', 'w+');
		$duplicate_log = fopen('duplicate_in_log','w+');
		//$duplicate_out_log = fopen('duplicate_out_log', 'w+');
		
		// loop over our buffer and split into individual packets
		$startsize = strlen($pkt_start_bytes);
		$endsize = strlen($pkt_end_bytes);

		start:
			// run any pending jobson the app object
			//$this->app->job();
//			if(count($receipt_nums) > 20000) {
//				exit();
//			}

		// reset all variables
		$pkt_start = 0;
		$payload_size = 0;
		$this_end_byte_offset = 0;
		$current_packet = '';
		$this_end_bytes = '';
		$discard = false;
		// check if we have enough bytes for a packet
//		if (strlen($buffer) < $min_pkt_size) {
//			return;
//		}

		// find our packet start
		$pkt_start = strpos($buffer, $pkt_start_bytes);
		// bail if we do not have a packet_start value
		if ($pkt_start === false) {
			exit();
		}
//		if (($pkt_start = strpos($buffer, $pkt_start_bytes)) === false) {
//			return;
//		}
		if (Daemon::$debug) {
			//Daemon::log('Found packet start at offset: ' . $pkt_start);
		}
		// good we found a packet, get our size
		list (, $payload_size) = unpack('n', binarySubstr($buffer, $pkt_start + $startsize, $pkt_size_bytes));
		
		// calculate the entire pkt_size once, we use it several times
		$pkt_size = $startsize + $pkt_size_bytes + $payload_size + $endsize;

		// check our buffer to make sure we have the entire packet
		if (strlen($buffer) < $pkt_start + $pkt_size) {
			//return;
			$error = "Buffer(".strlen($buffer).") does not contain enough bytes ({$pkt_size}) for this packet!";
			Daemon::log($error);
			echo $error."<br />\n";
			goto start;
		}
		
		$this_end_byte_offset = $pkt_start + $startsize + $pkt_size_bytes + $payload_size;
		$current_packet = binarySubstr($buffer, $pkt_start, $pkt_size );
		$this_end_bytes = binarySubstr($current_packet, $endsize * -1);
		
//		Daemon::log('$pkt_start:'.$pkt_start.', $pkt_size:'.$pkt_size.', $payload_size:'.$payload_size.', $this_end_byte_offset:'.$this_end_byte_offset);
//		Daemon::log('Current packet bytes('.strlen($current_packet).'):'.bin2hex($current_packet).'<-- end');
//		Daemon::log('$this_end_bytes:'.bin2hex($this_end_bytes));
		if ($this_end_bytes !== $pkt_end_bytes) {
			// some of the packets end up being 1 byte shorter than they should be
			// check for this condition before bailing
			if (binarySubstr($current_packet, -5, 4) === $pkt_end_bytes) {
				// move our packet end back one byte
				--$pkt_size;
				Daemon::log('Moving end of packet back one byte');
				$discard = true;
			} else {
				$error = "Incorrect end bytes! Found: ".bin2hex($this_end_bytes)." expected: ".bin2hex($pkt_end_bytes);
				Daemon::log($error."\n");
				echo $error."<br />\n";
				$discard = true;
				//exit();
				//goto start;
	//			Daemon::log('Wrong End Bytes.');
	//			$this->finish();
	//			return;
			}
				
		}
		//$this->event('data_recvd', binarySubstr($this->buf, $pkt_start, $pkt_size));
//		echo "Found packet! <br />
//			Start: {$pkt_start}<br />
//			Size: {$pkt_size}<br />
//			";
		
		// if this is one of the packets from the test server then discard
		if (strpos($current_packet, "\x23\x56\x49\x55\x46\x55\x4E\x4B\x4E\x4F\x57\x4E\x46\x49\x44") !== false) {
			Daemon::log('Discarding packet from test server!');
			$discard = true;
			//$buffer = binarySubstr($buffer, $pkt_start + $pkt_size);
			//goto start;
		}
		
		// discard the keep-alive response packets
		if ($pkt_size === 76) {
			Daemon::log('Discarding keep-alive response packet!');
			$discard = true;
			//$buffer = binarySubstr($buffer, $pkt_start + $pkt_size);
			//goto start;
		}
		
		// chop off this packet
		$buffer = binarySubstr($buffer, $pkt_start + $pkt_size);
		
		if ($discard) {
//			Daemon::log('$pkt_start:'.$pkt_start.', $pkt_size:'.$pkt_size.', $payload_size:'.$payload_size.', $this_end_byte_offset:'.$this_end_byte_offset);
//			Daemon::log('Current packet bytes('.strlen($current_packet).'):'.bin2hex($current_packet).'<-- end');
//			Daemon::log('$this_end_bytes:'.bin2hex($this_end_bytes));
			fwrite($reject_log, $current_packet."\r\n");
		}else {
			//$pkt_array[] = binarySubstr($buffer, $pkt_start, $pkt_size);
			$pkt_array[] = $current_packet;

			// save our completed packet into our output log
			fwrite($out_log, $pkt_array[count($pkt_array)-1]."\r\n");

			
			$pkt_count = count($pkt_array);
			//Daemon::log('We now have '.$pkt_count.' packets to process');
			if ($pkt_count % 100 === 0) {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'We now have '.$pkt_count.' packets to process');
			}
			//$this->app->processReceivedData($current_packet, $this->app->vendorconn, $this->app);
			$iso = new ISO8583Trans();
			
//			// this is for the stdout.log data
//			try{
//				$iso->addTCPMessage($current_packet);				
//				$time = $iso->getDataForBit(12, true); // HHMMSS
//				$date = $iso->getDataForBit(13, true); // MMDD
//				$submit_dt = '2013-'.substr($date,0,1).'-'.substr($date,-2).' '.substr($time,0,1).':'.substr($time,1,2).':'.substr($time,-2);
//				$trans_id = $iso->getDataForBit(37);
////				if (in_array($trans_id, $receipt_nums)) {
////					fwrite($duplicate_out_log, $current_packet);
////				} else {
////					$receipt_nums[] = $trans_id;
////				}
//				$sql = "INSERT INTO trans (submit_dt, trans_id) VALUES('{$submit_dt}', '{$trans_id}')";
//				Daemon::log('QUERY: '.$sql);
//				$sqlR = $sqli->query($sql);
//				if ($sqlR !== true){
//					Daemon::log('ERROR Query failed:'.$sql);
//				}
//				//$this->appInstance->vendorconn->sendData($current_packet);
//			}catch(Exception $e) {
//				Daemon::log('Exception CAUGHT! $e:'.$e);
//				Daemon::log('isoTCPMsg:'.bin2hex($current_packet));
//			}
			
			// 2013-05-01 this is for the stdin.log file
			try {
				$iso->addTCPMessage($current_packet);
				$trans_id = $iso->getDataForBit(37);
				$response_code = $iso->getDataForBit(39, true);
				try{
					$auth_iden = $iso->getDataForBit(38, true);
				}catch(Exception $e){
					Daemon::log('Bit 38 data not found for transaction:'.$trans_id.', response_code:'.$response_code);
					$auth_iden = '';
				}
				try{
					$avs_resp = $iso->getDataForBit(44, true);
				} catch(Exception $e) {
					Daemon::log('Bit 44 data not found for transaction:'.$trans_id.', response_code:'.$response_code);
					$avs_resp = '';
				}
				// get the id of this transaction
				$receipt_number = $trans_id;
				
				// we now grab all records that match the given receipt_number and then 
				// check each one
				$sqlT = "SELECT id, receipt_number, response_code, auth_iden_response, avs_response FROM fd_draft_data WHERE receipt_number = {$trans_id}";
				$sqlTR = $sqli->query($sqlT);
				if ($sqlTR === false) {
					echo "Query: {$sqlT} failed due to:".$sqli->error."<br />";
					continue;
				} else {
//					$ta = $sqlTR->fetch_all();
//					//$tr = $sqlTR->fetch_array();
					while($tr = $sqlTR->fetch_assoc()){
						if($tr['response_code'] === ''){
							$trans_id = $tr['id'];
							break;
						}
					}
					$sqlTR->free();
				}
				Daemon::log('Updating trans with bit37: '.$receipt_number);
				
				// now get the rest of the transaction data from the real fd_draft_data table
				$pri_acct_no_sql = '';
				$user_name_sql = '';
				$site_id_sql = '';
				$frozen_sql = '';
				$pkg_description_sql = '';
				$uv_sql = '';
				$draft_amount_sql = '';
				$tan_tax_amount_sql = '';
				$sqlF = "SELECT * FROM ultratan_drafts.fd_draft_data WHERE schedule_date = '2013-05-01' AND receipt_number = {$receipt_number}";
				$sqlFR = $sqli->query($sqlF);
				if ($sqlFR === false) {
					Daemon::log('ERROR Query failed:'.$sql.', error:'.$sqli->error);
				} else {
					$tr = $sqlFR->fetch_assoc();
					$pri_acct_no_sql = "pri_acct_no='{$tr['pri_acct_no']}', ";
					$user_name_sql = "user_name='{$tr['user_name']}', ";
					$site_id_sql = "site_id='{$tr['site_id']}', ";
					$frozen_sql = "frozen='{$tr['frozen']}', ";
					$pkg_description_sql = "pkg_description='{$tr['pkg_description']}', ";
					$uv_sql = "uv='{$tr['uv']}', ";
					$draft_amount_sql = "draft_amount='{$tr['draft_amount']}', ";
					$tan_tax_amount_sql = "tan_tax_amount='{$tr['tan_tax_amount']}' ";
					$sqlFR->free();
				}
					
				// update our fd_draft_data with the new data
				$sql = "UPDATE fd_draft_data SET 
						response_code='{$response_code}', auth_iden_response='{$auth_iden}', avs_response='{$avs_resp}',
						{$pri_acct_no_sql} {$user_name_sql} {$site_id_sql}
						{$frozen_sql} {$pkg_description_sql} {$uv_sql}
						{$draft_amount_sql} {$tan_tax_amount_sql}
						WHERE id = {$trans_id}";
				
//				$sql = "INSERT INTO response (submit_dt, trans_id, response_code, auth_iden) VALUES('{$submit_dt}', '{$trans_id}', '{$response_code}', '{$auth_iden}')";
				//Daemon::log('QUERY: '.$sql);
				$sqlR = $sqli->query($sql);
				if ($sqlR !== true){
					Daemon::log('ERROR Query failed:'.$sql);
				}
			}catch(Exception $e) {
				Daemon::log('Exception CAUGHT! $e:'.$e);
				Daemon::log('ERROR HexTCP:'.bin2hex($current_packet));
			}
			
			// 2013-05-02 This section is for creating new transaction entries
			// from the duplicate ISO messages
//			try{
//				$iso->addTCPMessage($current_packet);
//				//FIXME The $trans_table should not be hard-coded
//				$queries = SQL::buildQueriesForTransInsert($iso, 'fd_draft_data');
//				$sqlR = $sqli->query($queries[0]);
//				if ($sqlR !== true){
//					Daemon::log('ERROR Query failed:'.$queries[0].', error:'.$sqli->error);
//				}
//				// add our extra table entries
//			}catch(Exception $e){
//				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Exception re-creating ISO! $e:'.$e);
//				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'tcpData:'.bin2hex($current_packet));
//			}
			
//			// 2013-05-02 This section for processing the duplciate in log
//			try{
//				$iso->addTCPMessage($current_packet);
//			}catch(Exception $e) {
//				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Exception re-creating ISO! $e:'.$e);
//				Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'tcpData:'.bin2hex($current_packet));
//			}
			
			
		}
		
			
//		if ($pkt_count > 10) {
//			exit();
//		}

		if (strlen($buffer) < $min_pkt_size) {
			echo "Buffer no longer contains enough bytes for a packet!";
		} else {
			goto start;
		}
		
		echo "<h3>We have ".count($pkt_array)." packets to process</h3>\n";
		?>
	</div>
	<div>
		<h4>This section just for testing. Remove for production code.</h4>
		<button onclick="create();">Create WebSocket</button>
		<button onclick="ws.send('ping');">Send ping</button>
		<button onclick="ws.close();">Close WebSocket</button>
		<div id="log" style="width:600px; height: 100px; border: 1px solid #999999; overflow:auto;"></div><br />
		<button onclick="ws.send('command');">Send Command</button><button onclick="sendObject({command:'send_trans',trans_id:'1234'});">Send Object</button><br />
		<button onclick="sendText();">Send Text</button><input type="text" name="command" id="command" />
	</div>
	<div><pre><?php print_r($req->attrs); ?></pre></div>
</body>
</html>