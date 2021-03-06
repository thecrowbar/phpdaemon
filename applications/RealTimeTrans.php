<?php
/**
 * RealTimeTrans - appInstance to communicate with remote vendor over TCP socket
 * and process ISO8583 messages
 *
 * @author James Crow (jcrow@daemon.io)
 */
class RealTimeTrans extends Vendor{
	const PRIVATE_KEY = 'file:///opt/phpdaemon/test-rsa-2048-privkey.pem';
	
	/**
	 * File name to save queries to if $log_queries is set
	 * @var String
	 */
	public $query_log_file = '/opt/phpdaemon/log/realtimetrans.queries.log';
	
	/**
	 * Web Socket Route to use 
	 * @var String
	 */
	public $WebSocketRoute = 'RealTimeTransWS';
	
	/**
	 * @var $terminals TerminalInfo[] 
	 */
	public $terminals;
	
	/**
	 * Saves the Request object callback timer. This is mainly for testing
	 * @var Timer
	 */
	public $pending_req_timer;
	
	/**
	 * Maximum number of transactions to pull at once
	 * @var Int
	 */
	public $pending_trans_limit = '1000';
	
	/**
	 * Array of timers that initiate on each sent transaction and clear when a
	 * response is received. If no response is received then a reversal 
	 * transaction must be sent
	 * @var Timer[]
	 */
	public $auto_reversal_timers = array();
	
	/**
	 * The number of seconds an auto-reverse timer should wait before sending
	 * an automatic transaction reversal. Set to 0 to disable.
	 * @var Int
	 */
	public $auto_reversal_timeout = 40;
	
	/**
	 * The DB table name that stores the transaction data
	 * @var String
	 */
	public $trans_table = 'fd_trans';
	/**
	 * Should the draft be automatically sent to FD
	 * @var Bool
	 */
	public $auto_submit_draft = false;
	
	/**
	 * The hour to auto submit draft. If after this time, the DB will checked every 
	 * keep-alive timeout seconds for pending transactions
	 * @var int
	 */
	public $auto_submit_time = 25;
	
	/**
	 * Debugging flag indicates if HTTP Request Parameters hsould be logged
	 * @var Bool
	 */
	public $log_HTTP_Requests = false;
	
	/**
	 *Filename to save logged requests to
	 * @var String
	 */
	public $log_HTTP_Request_file = '/opt/phpdaemon/log/RealTimeTransRequests.log';
	
	
//	/**
//	 * First method called when a new object is created.
//	 */
//	public function init() {
//		parent::init();
//
//	}
	
	
	/**
	 * processReceivedData() - process the data returned by the remote vendor
	 * @param byte[] $msg - the data received from the vendor
	 * @param RealTimeTrans $app - a link back to this object
	 * @param Bool $duplicate - dummy variable; not used
	 * @param Int $trans_id - if this is a duplicate, then this is the second trans DB record id
	 */
	public function processReceivedData($msg, $app, $duplicate = false, $trans_id = -1){
		$app = $this;
		// this closure handles the incoming data
		// VendorClientConnection has done basic sanity checks to ensure 
		// a complete message has made it through
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'data_recvd handler fired! Processing '.strlen($msg).' bytes of data.');

		// $msg is a string of bytes from TCP socket convert it into an object
		try {
			$iso_msg = new ISO8583Trans();
			$iso_msg->addTCPMessage($msg);
			$msg = $iso_msg;
		} catch(Exception $e){
			Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'Exeception caught trying to recreate ISO8583 from TCP data! Exception:'.$e);
			return;
		}
		if ($msg->msg_type === ISO8583::MSG_TYPE_AUTH_RESP 
				|| $msg->msg_type === ISO8583::MSG_TYPE_REV_RESP) {
			// this is a repsone to one of our messages, we need some information from the database
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Processing an AUTH_RESP or REV_RESP message');
			
			// get a MySQl connection to update the record in the DB
			$sql_conn = $app->sql->getConnection(function($sql) use ($app, $msg){
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __FILE__.':'.__METHOD__.':'.__LINE__.' executing 2');

				$query = SQL::buildQueryForOriginalTrans($msg, $app->trans_table);
				//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'About to execute query:'.$query);
				$sql->query($query, function($sql, $success) use($app, $msg, $query){
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __FILE__.':'.__METHOD__.':'.__LINE__.' executing 3');
					// check for a successful sql query
					if ($success === false) {
						Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'Failed to run query:'.$query.' SQL Error:'.$sql->errmsg);
						return;
					}else{
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __FILE__.':'.__METHOD__.':'.__LINE__.' executing');
					}
					// get the data we need from our Database
					$sql_results = $sql->resultRows;
					
					if (count($sql_results) !== 1) {
						Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'Incorrect number of results found for query:'.$query);
					}
					$sr = $sql_results[0];
					
					// add data from the original trans into our new object
					try{
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'DB record id:'.$sr['id']);
						$msg->original_trans_id = (int)$sr['id'];
						$msg->master_trans_id = (int)$sr['master_trans_id'];
						$msg->_trans_type = (int)$sr['trans_type'];
						$cancel_timer_id = ($msg->master_trans_id === -1) ? $msg->original_trans_id:$msg->master_trans_id;
						Vendor::logger(Vendor::LOG_LEVEL_INFO, __METHOD__.' About to cancel reversal timer for transID:'.$cancel_timer_id);
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' $msg->original_trans_id:'.$msg->original_trans_id);
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' $msg->master_trans_id:'.$msg->master_trans_id);
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' $msg->retrieval_reference_number:'.$msg->retrieval_reference_number);
						//Vendor::logger(Vendor::LOG_LEVEL_INFO, __METHOD__.':'.__LINE__.' $sr:'.print_r($sr, true));
						$app->clearAutoReversalTimer($cancel_timer_id);
						$msg->original_trans_amount = $sr['trans_amount'];
						if (self::$decrypt_data) {
							$data = Vendor::decrypt_data($sr['pri_acct_no']);
							if (is_array($data)){
								throw new Exception('Error decrypting account data! Error:'.$data['error_msg']);
							}else {
								$msg->pri_acct_no = $data;
								$msg->credit_card = new CreditCard($msg->pri_acct_no);
								$msg->card_type = $msg->credit_card->card_type;
							}
						} else {
							// pull the card type from the original trans query data
							switch($sr['cc_type']) {
								case 'VS':
									$msg->card_type = 'Visa';
									break;
								case 'MC':
									$msg->card_type = 'Master Card';
									break;
								case 'AX':
									$msg->card_type = 'American Express';
									break;
								case 'DS':
									$msg->card_type = 'Discover';
									break;
								default:
									Vendor::logger(Vendor::LOG_LEVEL_ERROR, "User: {$sr['user_name']}, has unknown card type: {$sr['cc_type']}");
							}
						}
						
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Finished adding data from original trans!');
					}catch(Exception $e){
						Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'Exception caught trying to update transaction with original trans data! Exception:'. $e);
					}

					// at this point we have a complete transaction response
					// we need to update the original transaction in the database
					$app->updateTransInDB($msg, $app, null, function() use($app, $msg){
						// wake the original job up
						$transID = $msg->original_trans_id;
						if (array_key_exists($transID, $app->pending_requests) && is_object($app->pending_requests[$transID])) {
							Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Found our request object for transID:'.$transID);
							$req = $app->pending_requests[$transID];
							$this->pending_req_timer = setTimeout(function($timer) use($req, $msg, $transID) {
								Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.':'.__LINE__.' timer with ID: '.$transID.'callback firing');
								//$app->checkOutboundQueue($app);
								$req->job->setResult('trans_msg', $msg);
								$req->wakeup();
							}, 1e6 * 2);
							//setTimeout(function() use ($req){$req->wakeup()}, 300);
							//$req->wakeup();
						} else {
							Vendor::logger(Vendor::LOG_LEVEL_INFO, 'No pending request found for transID:'.$transID);
						}
						//$this->pending_requests[$transID]->wakeup();
						//Vendor::log(Vendor::LOG_LEVEL_DEBUG, print_r($this->pending_requests, true));

					});
					

					// update the client over websocket that we received a response
					if (is_object($app->ws)) {
						$app->updateClientWS($app->ws, $msg);
					} else {
						Vendor::logger(Vendor::LOG_LEVEL_INFO, '$app->ws is not an object! Unable to send response over websocket!');
					}


					// output some basic debugging data to log file
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, $msg->getBasicDebugData());
					if (method_exists($msg, 'getBit63DebugData')){
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, $msg->getBit63DebugData());
					}
				});

			});
		}
	}
	
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param RealTimeTrans Upstream application instance.
	 * @return RealTimeTransRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' running');
		//Vendor::log(Vendor::LOG_LEVEL_DEBUG, '$req:'.print_r($req, true));
		//Vendor::log(Vendor::LOG_LEVEL_DEBUG, 'About to attempt parsing of $req->attrs:'.print_r($req->attrs, true));
		$req_params = RealTimeTransRequest::importRequestValues($req->attrs);
//		if ($this->log_HTTP_Requests === true) {
//			$ofile = fopen($this->log_HTTP_Request_file, 'w+');
//			if ($ofile) {
//				fwrite($ofile, print_r($req_params, true)."\n\n\n");
//			}
//		}
		Vendor::logger(Vendor::LOG_LEVEL_INFO, '$req_params:'.print_r($req_params, true));
		if (array_key_exists('transID', $req_params)) {
			$transID = $req_params['transID'];
			
			$this->pending_requests[$transID] = new RealTimeTransRequest($this, $upstream, $req);
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.': pending_requests() now contains:'.count($this->pending_requests).' objects');
			return $this->pending_requests[$transID];			
		} else {
			return new RealTimeTransRequest($this, $upstream, $req);
		}
	}
}
?>
