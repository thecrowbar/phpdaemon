<?php
/**
 * Description of Vendor
 *
 * @author jcrow
 */
class Vendor extends AppInstance{
	/**
	 * RSA Private Key used to decrypt data\
	 * WARNING WARNING WARNING - You must create your own key pair! Do not use this one!
	 */
	const PRIVATE_KEY = 'file:///opt/phpdaemon/test-rsa-2048-privkey.pem';
	//const PRIVATE_KEY = 'file:///opt/phpdaemon/rsa-1024-priv.key';
	
	/**
	 * Messages usefule for debugging
	 */
	const LOG_LEVEL_DEBUG = 9;
	/**
	 * Informational messages. May be too chatty for normal use.
	 */
	const LOG_LEVEL_INFO = 8;
	/**
	 * Not an error, but may require special handling
	 */
	const LOG_LEVEL_NOTICE = 7;
	/**
	 * Warning
	 */
	const LOG_LEVEL_WARNING = 6;
	/**
	 * Ordinary error
	 */
	const LOG_LEVEL_ERROR = 5;
	/**
	 * Critical condition
	 */
	const LOG_LEVEL_CRITICAL = 4;
	/**
	 * Indicates condition should be corrected immediately
	 */
	const LOG_LEVEL_ALERT = 3;
	/**
	 * Indicates and imminent crash or other major fault condition
	 */
	const LOG_LEVEL_EMERGENCY = 2;
	
	/**
	 * Log Messages at or below this level will be sent to the phpd log
	 * @var Int
	 */
	public static $log_level = self::LOG_LEVEL_DEBUG;
	
	public static $log_tcp_stream = true;
	
	//<editor-fold defaultstate="collapsed" desc="Class properties to hold config settings">
	/**
	 * database object
	 * @var MySQLClient Object
	 */
	public $sql;
	
	/**
	 * database connection object
	 * @var MySQlClientConnection opbject
	 */
	public $sql_conn;
	
	/**
	 * This is our NetworkClient that connects to remote vendor
	 * @var VendorClient Object
	 */
	public $vendorclient;
	
	/**
	 * The connection to the remote vendor
	 * @var VendorClientConnection Object
	 */
	public $vendorconn;
	
	/**
	 * The vendor message
	 * @var VendorMessage Object
	 */
	public $vendorMessage;
	
	/**
	 * Remote vendor IP Address or hostname to connect to. This can be multiple
	 * IP addresses separated by commas to support multiple remote endpoints
	 * @var String
	 */
	public $vendorhosts;
	
	/**
	 * Remote vendor TCP port to connect to
	 * @var int
	 */
	public $vendorport;
	//</editor-fold>
	
	/**
	 * The queue to hold transaction before they are sent out
	 * @var SplQueue
	 */
	public $tq;
	
	/**
	 * websoucket object to communicate with the client
	 * @var VendorWebSocketRoute object
	 */
	public $ws;
	
	/**
	 * The closure that handles communication over the web socket
	 * @var VendorWebSocketRoute Object
	 */
	public $ws_closure;
	
	/**
	 * Web Socket Route to use 
	 * @var String
	 */
	public $WebSocketRoute = 'VendorWS';
	
	/**
	 * Flag that signals the decryption of the account number from the original
	 * transaction. This is no longer needed because the card type is pulled
	 * from the original transaction instead.
	 * @var bool
	 */
	public static $decrypt_data = true;
	
	/**
	 * These are the only usable test numbers
	 */
	public static $TEST_SET_NUMS = array(
		// removed because these may be special account numbers
	);
	
	/**
	 * Should we only allow test data numbers
	 * @var bool
	 */
	public static $test_set_only = false;
	
	//<editor-fold defaultstate="collapsed" desc="Class properties related to DB">
	/**
	 * Flag to indicate if queries should be logged instead of executed
	 * @var bool
	 */
	public static $log_queries = true;
	
	/**
	 * Table name to pull/store transaction information
	 * @var String
	 */
	public $trans_table = 'fd_draft_data';
	
	/**
	 * File name to save queries to if $log_queries is set
	 * @var String
	 */
	public $query_log_file = '/opt/phpdaemon/log/vendor.queries.log';
	
	/**
	 * File handle for the query log
	 * @var Resource
	 */
	public $qfile;
	//</editor-fold>
	
	/**
	 * Requests that are waiting on responses from FD
	 * @var Array[]
	 */
	public $pending_requests = array();
	
	/**
	 * ComplexJob to store and run our jobs
	 * @var ComplexJob
	 */
	public $job;
	
	/**
	 * Stores the last 1000 transactions sent out. This is an assoc array of
	 * TID=>array(Transaction IDs (receipt numbers))
	 * @var type 
	 */
	public $recent_trans = array();
	
	//<editor-fold defaultstate="collapsed" desc="Class Properties to control auto processing">
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
	public $auto_submit_time = 5;
	
	/**
	 * Should we automatically submit the settlement (capture) transactions
	 * @var Bool
	 */
	public $auto_settle_transactions = false;
	
	/**
	 * Time (hour) that we should send auto settlement transactions
	 * @var Int
	 */
	public $auto_settle_time = 16;
	
	/**
	 * Flag that controls whether the processDraft() method actually does anything.
	 * This is set to false when a duplicate transaction is detected.
	 * @var Bool
	 */
	public $allow_draft_processing = true;
	//</editor-fold>
	
	/**
	 * Sets the name of the class to determine if we are child 
	 * @var String
	 */
	public $class_name = '';
	
	//<editor-fold defaultstate="collapsed" desc="Class properties that control load logging">
	public $log_draft_stats = false;
	public $log_draft_stat_interval = 60;
	public $log_draft_stats_file = "/opt/phpdaemon/log/draft_log_stats.csv";
	public $lfile = '';
	public $log_draft_stats_timer = '';
	//</editor-fold>
	
	
	/**
	 * First method called when a new object is created.
	 */
	public function init() {
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' start');
		// start xdebug trace of our code
		//xdebug_start_trace('/opt/phpdaemon/log/Vendor.xt');
		
		// give ourself plenty of RAM
		ini_set('memory_limit', '900M');
		
		// set our config values for easier access
		$this->vendorhost = $this->config->vendorhosts->value;
		$this->vendorport = $this->config->vendorport->value;
		
		// get an intial connection
		$svr_config = new Daemon_ConfigSection(array('servers'=>"{$this->vendorhost}", 'port'=>"{$this->vendorport}"));
		$this->vendorclient = VendorClient::getInstance($svr_config, $this);
		
		// get our MySQL connection. All classes that use this appInstance can
		// get the same MySQL connection without needing the login credentials
		$sql_user = $this->config->sqluser->value;
		$sql_pass = $this->config->sqlpass->value;
		$sql_host = $this->config->sqlhost->value;
		$sql_db = $this->config->sqldb->value;
		$mysql_config = new Daemon_configSection(
				array(
						'server'=>"tcp://{$sql_user}:{$sql_pass}@{$sql_host}/{$sql_db}"
				)
		);
		$this->sql = MySQLClient::getInstance($mysql_config);
		//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this->sql:'.print_r($this->sql, true));
		
		// create a connection to the MySQL server for use later
		$this->sql_conn = $this->sql->getConnection();
		
		// initialize our queue to hold outgoing transactions
		$this->tq = new SplQueue();
		
		// setup our log file to save queries; if flag is set
		if (self::$log_queries) {
			$this->qfile = fopen($this->query_log_file, 'a+');
			if ($this->qfile === false) {
				Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Unable to open the query log file!');
			}else {
				fwrite($this->qfile, __CLASS__.' starting up at '.date('Y-m-d H:i:s')."\n\n");
			}
		}
		
		// if set to log stats open our log file
		if ($this->log_draft_stats) {
			$this->lfile = fopen($this->log_draft_stats_file, 'w+');
		}
		
		$this->class_name = get_class($this);
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' end');
	}
	
	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' start');
		// if we have a client object, make sure it is ready and connected
		if ($this->vendorclient) {
			$this->vendorclient->onReady();
			$this->connect();
		}

		// create a route for websocket use 
		// Default WebSocket port is 8047.
		// The path is 'VendorWS'. Change it below if required
		$appInstance = $this; // a reference to this application instance for the WebSocketRoute
		WebSocketServer::getInstance()->addRoute($this->WebSocketRoute, function ($client) use ($appInstance) {
			return new VendorWebSocketRoute($client, $appInstance);
		});
		
		// create a ComplexJob object to store and run our saved jobs
		$this->job = new ComplexJob();
		
//		// start a timer to check the outbound queue every 90 seconds
//		$app = $this;
//		$this->keepaliveTimer = setTimeout(function($timer) use($app) {
//			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' timer callback firing');
//			$app->checkOutboundQueue($app);
//		}, 1e6 * 900);
//		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' end');
		
		//if set to log draft stats start a timer for it
		if ($this->log_draft_stats){
			$app = $this;
			$this->log_draft_stats_timer = new Timer(function($timer) use($app){
				Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Stat log timer firing');
				$app->saveStats();
				// restart our timer
				$timer->timeout($app->log_draft_stat_interval * 1e6);
			}, $this->log_draft_stat_interval*1e6);
		}
	}
	
	/**
	 * Establish a connection to the remote vendor and start a timer to send 
	 * keepalive messages
	 */
	public function connect() {
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' running');
		$app = $this;
		$url = '';
		// check for a passed callback and execute it when the job completes
		$args = func_get_args();
		$callbacks = array();
		if (count($args) > 0) {

			// check the first arguments to see if it a url
			if (!is_object($args[0]) && !is_callable($args[0])) {
				$url = array_shift($args);
			}
			// make sure the argument is callable
			foreach($args as $arg) {
				if (is_object($arg) && is_callable($arg)) {
					$callbacks[] = $arg;
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' callback added!');
				} else {
					Vendor::logger(Vendor::LOG_LEVEL_NOTICE, $arg.' is not an object and not callable');
				}
			}
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Found '.count($callbacks).' callbacks for '.__METHOD__);
		
		// check our connection, if it is connected then we run callbacks and return
		if (!is_object($app)) {
			Vendor::logger(Vendor::LOG_LEVEL_CRITICAL, '$app is not an object. FATAL! $this:'.print_r($this, true));
		}
		if (is_object($app->vendorconn) && $app->vendorconn->isConnected()) {
			if (count($callbacks) > 0) {
				foreach($callbacks as $cb) {
					call_user_func($cb);
				}
			}
			//return true;
		} else {

			// if we have a url use it instead of the an auto selected default
			if (strlen($url) > 0) {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.':'.__LINE__.': url:'.$url);
				$app->vendorclient->getConnection( $url, function ($conn) use ($app, $callbacks) {
					$app->vendorconn = $conn;
					if ($conn->isConnected()) {
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, get_class($app).' connected at '.$conn->getHost().':'.$conn->getPort());
						self::attachEventHandlers($conn, $app);

						// call our callback functions
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Vendor->connect()->closure(), about to call '.count($callbacks).' callbacks');
						foreach($callbacks as $cb) {
							call_user_func($cb);
						}
					}
					else {
						Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'VendorClient: unable to connect ('.$conn->getHost().':'.$conn->getPort().')');
					}
				});
			} else {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.':'.__LINE__.': using an auto selected value from the server list');
				// use an auto selected value from the servers list
				$obj = $app->vendorclient->getConnection( function ($conn) use ($app, $callbacks) {
					$app->vendorconn = $conn;
					if ($conn->isConnected()) {
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, get_class($app).' connected at '.$conn->getHost().':'.$conn->getPort());

						self::attachEventHandlers($conn, $app);

						// call our callback functions
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Vendor->connect()->closure(), about to call '.count($callbacks).' callbacks');
						foreach($callbacks as $cb) {
							call_user_func($cb);
						}
					}
					else {
						Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'VendorClient: unable to connect ('.$conn->getHost().':'.$conn->getPort().')');
					}
				});
				if (is_object($obj) && !$obj->isConnected()) {
					// connection failed. try other IP
					$srv_list = explode(',',$app->vendorclient->config->servers->value);
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Servers list:'.print_r($srv_list, true));
				} else if($obj === false) {
					Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'Unable to connect! '.__METHOD__.':'.__LINE__);
				}
			}
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' end reached');
	}
	
	/**
	 * processReceivedData() - process the data returned by the remote vendor
	 * @param String $msg - the data received from the vendor
	 * @param Vendor $app - a link back to this object
	 * @param Bool $duplicate - is the second of a duplicate transaction
	 * @param Int $trans_id - is this is a duplicate, this should be the new DB record ID
	 */
	public function processReceivedData($msg, $app, $duplicate = false, $trans_id = null){
		$app = $this;
		// this closure handles the incoming data
		// VendorClientConnection has done basic sanity checks to ensure 
		// a complete message has made it through
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'data_recvd handler fired! Processing '.strlen($msg).' bytes of data.');

		// $msg is a string of bytes from TCP socket convert it into an object
		try {
			$iso = new ISO8583Trans();
			$iso->addTCPMessage($msg);
			//$msg = $iso_msg;
		} catch(Exception $e){
			Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Exeception caught trying to recreate ISO8583 from TCP data! Exception:'.$e);
			return;
		}
		if ($iso->msg_type === ISO8583::MSG_TYPE_AUTH_RESP 
				|| $iso->msg_type === ISO8583::MSG_TYPE_REV_RESP) {
			// this is a repsone to one of our messages, we need some information from the database
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Processing an AUTH_RESP or REV_RESP message');
			
			// we first get the DB record id of the transaction and then update
			// several tables with data from the response
			if ($duplicate === true) {
				// put our new DB record IS into the message, overriding the
				// original value. This ensures the query to recover the remainder
				// of the data will pull the correct entry.
				Vendor::logger(Vendor::LOG_LEVEL_INFO,'Overwriting bit37 data with:'.$trans_id);
				$iso->retrieval_reference_number = $trans_id;
				$iso->original_trans_id = $trans_id;
			}
			$q = SQL::buildQueryForOriginalTrans($iso, $this->trans_table, $duplicate);
			$id = ($iso->original_trans_id===-1) ?$iso->receipt_number:$iso->original_trans_id;
			Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Transaction using id:'.$id.', $msg->receipt_number:'.$iso->receipt_number.', $msg->original_trans_id:'.$iso->original_trans_id);
			$name = $id.'-trans_orig_rec';
			$app->createJobFromQuery($app, null, $name, $q, false, 
					function($result)use($app, $iso, $name, $q, $msg, $id, $duplicate){
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG,'Job callback for job name:'.$name);
				// process our response into a complete ISO8585 msg
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'About to call createResponseMsg() and pass in:'.print_r($result, true));
				if (count($result) > 1) {
					Vendor::logger(Vendor::LOG_LEVEL_ALERT, 'Query: '.$q.' returned '.count($result).' rows! $id:'.$id.' $duplicate:'.$duplicate);
					Vendor::logger(Vendor::LOG_LEVEL_ALERT, '$result:'.print_r($result, true));
				}
				$iso = $app->createResponseMsg($iso, $result[0], $msg);
				
				if ($iso === false) {
					// this happens when we detect a duplicate response.
					// the duplicate check process will create a second transaction entry 
					// and then call this function again
					return;
				}

				// at this point we have a complete transaction response
				// we need to update the original transaction in the database
				$app->updateTransInDB($iso, $app);

				// update the client over websocket that we received a response
				if (is_object($app->ws)) {
					$app->updateClientWS($app->ws, $iso);
				} 

				// output some basic debugging data to view
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, $iso->getBasicDebugData());
				if (method_exists($iso, 'getBit63DebugData')){
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, $iso->getBit63DebugData());
				}
			}); // end of 'trans_orig_rec' callback
		} else {
			// we received a message that is not AUTH_RESP or REV_RESP; log it
			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Received an ISO8583 message that is not 0110 or 0410! mti:'.$iso->getMTI(true));
		}
	}
	
	/**
	 * createResponseMsg() - create complete ISO8583Trans object from the returned message data
	 * @param ISO8583Trans $iso - the empty ISO8583 trans object
	 * @param Array $sr - the DB record from the original transaction.
	 * @param String $msg - binary data as received from the vendor
	 * @return ISO8583Trans
	 * @throws Exception
	 */
	public function createResponseMsg($iso, $tr, $msg){
		//Vendor::logger(Vendor::LOG_LEVEL_INFO, __METHOD__.' called with arguments: $msg:'.print_r($msg, true).', $tr:'.print_r($tr, true));
		// first check if we already have a response_code. If so, then this is
		// a duplicate transaction
		if (strlen($tr['response_code']) !== 0 && $tr['capture_submit_dt'] === '0000-00-00 00:00:00') {
			Vendor::logger(Vendor::LOG_LEVEL_EMERGENCY, 'Duplicate authorization response detected for receipt_number:'.$iso->receipt_number.' Stopping draft processing');
			$this->auto_submit_draft = false;
			$this->allow_draft_processing = false;
			Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Duplicate transaction has original id:'.$tr['id']);
			$this->createNewTrans($iso, $tr, $msg);
			//Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Duplicate transaction has new id:'.$tr['id']);
			return false;
		}
		// add data from the original trans into our new object
		try{
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Original trans id:'.$tr['id']);	
			$iso->original_trans_id = $tr['id'];
			$iso->original_trans_amount = $tr['trans_amount'];
			if (self::$decrypt_data) {
				$data = self::decrypt_data($tr['pri_acct_no']);
				if (is_array($data)){
					throw new Exception('Error decrypting account data! Error:'.$data['error_msg']);
				}else {
					// save our encrypted account number
					$iso->encrypted_acct_no = $tr['pri_acct_no'];
					$iso->pri_acct_no = $data;
					$iso->credit_card = new CreditCard($iso->pri_acct_no);
					$iso->card_type = $iso->credit_card->card_type;
				}
			} else {
				// pull the card type from the original trans query data
				switch($tr['cc_type']) {
					case 'VS':
						$iso->card_type = 'Visa';
						break;
					case 'MC':
						$iso->card_type = 'Master Card';
						break;
					case 'AX':
						$iso->card_type = 'American Express';
						break;
					case 'DS':
						$iso->card_type = 'Discover';
						break;
					default:
						Vendor::logger(Vendor::LOG_LEVEL_ERROR, "User: {$tr['user_name']}, has unknown card type: {$tr['cc_type']}");
				}
			}
			// make sure we have a trans type
			if ($iso->_trans_type === '') {
				$iso->_trans_type = $tr['trans_type'];
			}

			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Finished adding data from original trans!');
		}catch(Exception $e){
			Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Exception caught trying to update transaction with original trans data! Exception:'. $e);
		}
		return $iso;
	}
	
	/**
	 * createISOandSend() - given a DB record id, create an ISO8583 message and
	 * submit
	 * @param RealTimeTrans $app - the appInstance we are communicating with
	 * @param RealTimeTransRequest $req - the HTTP Request for this transaction
	 * @param type $id - the DB record id to send
	 * @param type $track2 - the track2 data from a card swipe
	 * @param String $cvc - the Card Security Code from the credit card
	 * @param Bool $settle - is this a settlement of a previously authorized transaction
	 * @param Closure $cb - additional callback to execute once the transaction is sent
	 */
	public function createISOandSend($app, $req, $id, $track2 = null, $cvc = null, $settle = false,
			$cb=null) {
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.': We need to create an ISO8553 Object and send it! $id:'.$id);
		// if we have a $req object store it
		if (is_object($req)) {
			//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.': returning an existing ')
			$app->pending_requests[$id] = $req;
		}
		$q = SQL::singleTransDetailQuery($id, $this->trans_table);
		$app->executeQuery($app, $q, function($result) use($app, $req, $track2, $cvc, $cb, $id, $settle){
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'createISOandSend(), $id:'.$id.' $result:'.print_r($result, true));
			// first check if this transaction was already submitted
			if (is_array($result)) {
				$tr = $result[0];
				if ($settle === true){
					if ($tr['acquirer_reference_data'] === '0') {
						$tr['acquirer_reference_data'] = '2';
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Changing $tr[acquirer_reference_data] from 0 to 2 for trans:'.$tr['id']);
					} else {
						Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Trans ('.$tr['id'].
								') attempting to settle, but original trans not auth only!'.
								'acquirer_reference_data:'.$tr['acquirer_reference_data'].
								' $settle:'.$settle);
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$tr[acquirer_reference_data] is of type:'.Vendor::get_type($tr['acquirer_reference_data']));
						return;
					}
				}else {
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$settle !== true for trans('.$id.')');
				}
				// add in our submit_dt, track2, and cvc data
				$tr['track2'] = $track2;
				$tr['table49'] = $cvc;
				//$tr['submit_dt'] = date('Y-m-d H:i:s');
				if ($tr['trans_type'] == ISO8583Trans::TRANS_TYPE_REVERSAL) {
					// reversal trans use the trans_dt value because it matches the 
					// original auth submit_dt
					$tr['submit_dt'] = $tr['trans_dt'];
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Using the trans_dt value for the submit_dt value because this is a REVERSAL');
				} else {
					$tr['submit_dt'] = date('Y-m-d H:i:s');
				}
//				if ($tr['acquirer_reference_data'] === '2') {
//					// this is a capture only transaction; the date time should match the 
//					// auth only transactions *** wrong per Ed Perez email dated 5/30/13 3:04PM
//					//$tr['submit_dt'] = $tr['auth_submit_dt'];
//				} else {
//					// this is the first transaction. Set datetime to current
//					if ($tr['trans_type'] == ISO8583Trans::TRANS_TYPE_REVERSAL) {
//						// reversal trans use the trans_dt value because it matches the 
//						// original auth submit_dt
//						$tr['submit_dt'] = $tr['trans_dt'];
//						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Using the trans_dt value for the submit_dt value because this is a REVERSAL');
//					} else {
//						$tr['submit_dt'] = date('Y-m-d H:i:s');
//					}
//				}
				
				// the TIMEOUT_REVERSAL messages can have a duplicate submit_dt value. 
				// These are sent continuously until a response is received
				// REVERSAL transactions should match the original auth transaction
				if ($tr['trans_type'] !== ISO8583Trans::TRANS_TYPE_TIMEOUT_REVERSAL
						|| $tr['trans_type'] !== ISO8583Trans::TRANS_TYPE_REVERSAL) {
					// we can have three types of transactions. Depending on their
					// acquirer_reference_data value
					switch($tr['acquirer_reference_data']) {
						case '0':
							// this is an auth only request; no auth_submit_dt or capture_submit_dt value
							if ($tr['auth_submit_dt'] !== '0000-00-00 00:00:00' 
									|| $tr['capture_submit_dt'] !== '0000-00-00 00:00:00') {
								Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Transaction ('.
										$tr['id'].') of type ('.$tr['trans_type'].
										') was authorized:'.$tr['auth_submit_dt'].
										' & captured:'.$tr['capture_submit_dt']);
								return;
							} 
							break;
						case '1':
							// this is an auth and capture request; no auth_submit_dt or capture_submit_dt value
							if ($tr['auth_submit_dt'] !== '0000-00-00 00:00:00' || $tr['capture_submit_dt'] !== '0000-00-00 00:00:00') {
								Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Transaction ('.
										$tr['id'].') of type ('.$tr['trans_type'].
										') was authorized:'.$tr['auth_submit_dt'].
										' & captured:'.$tr['capture_submit_dt']);
								return;
							} 
							break;
						case '2':
							// this is a capture only request; no capture_submit_dt value
							if ($tr['capture_submit_dt'] !== '0000-00-00 00:00:00') {
								Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Transaction ('.
										$tr['id'].') of type ('.$tr['trans_type'].
										') was authorized:'.$tr['auth_submit_dt'].
										' & captured:'.$tr['capture_submit_dt']);
								return;
							} else {
								// set our capture_dt to now
								$tr['capture_submit_dt'] = date('Y-m-d H:i:s');
							}
							break;
						default:
							Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Trans ('.$tr['id'].') has unknown acquirer_reference_data:'.$tr['acquirer_reference_data']);
							return;
					}
				} else {
					// TODO check the 0400 message auth_submit_dt and capture_submit_dt
					// a 0400
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'REVERSAL trans datetime should match original auth only, but we do not have it! Data:'.print_r($tr, true));
				}
				
				// if we get here then the submit_dt checks passed
				$q = SQL::updateSubmitDTQuery($tr['id'], $app->trans_table, $tr);
				$app->executeQuery($app, $q, function($result) use ($app, $tr, $cb){
					if ($result !== 1) {
						Vendor::logger(Vendor::LOG_LEVEL_CRITICAL, 'Trans ('.$tr['id'].') failed to update the auth_submit_dt/capture_submit_dt!');
						return;
					}
					// send our ISO8583 message
					try {
						$msg = new VendorMessage($tr);

						// if this is an original auth request, then 
						// we must start an auto reversal timer
						if ($msg->ISO8583->master_trans_id === -1 
								&& $msg->ISO8583->_trans_type === ISO8583Trans::TRANS_TYPE_RETAIL) {
							// this is the first auth message start our timer
							// the timer will re-call itself everytime it expires
							// and keep doing that until it receives a response
							$app->createAutoReversalTimer($app, $msg->ISO8583->_trans_id);

						}
						$app->vendorMessage = $msg;
						// send our iso
						// add the message to our queue
						$app->tq->enqueue($app->vendorMessage);
						//Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'NOTICE! Not Adding message to outbound queue');

						// send the data to the remote vendor
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'About to call Vendor->connect and pass in callback to checkOutboundQueue');
						$app->connect(function() use ($app){
							$app->checkOutboundQueue($app);
						});
						Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'After the $app->connect() call in createISOandSend(). About to check for callback');
						 // execute our callback (if any)
						if (isset($cb) && is_callable($cb)) {
							Vendor::logger(Vendor::LOG_LEVEL_DEBUG,' About to call user function at '.__FILE__.':'.__LINE__);
							call_user_func($cb);
						} else {
							Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.': $cb not set or not callable!');
						}
					}catch(Exception $e){
						Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Exception caught trying to create ISO8583! $e:'.$e);
					}
				}); // end of executeQuery callback that updates the submit_dt values
			} else { // end of is_array($result)
				Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Query for transaction id ('.$id.') did not return an array! $result:'.print_r($result, true));
			}
		}); // end of outer executeQuery callback
	}
	
	/**
	 * createAutoReversalTimer() - create a timer to submit an automatic reversal
	 * if no response is received
	 * @param RealTimeTrans $app - the main appInstance
	 * @param int $id - the original transaction ID from the DB (Bit 37 value)
	 */
	public function createAutoReversalTimer($app, $id){
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.':'.__LINE__.' We have '.count($app->auto_reversal_timers).' timers currently defined!');
		//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$pp->auto_reversal_timers:'.print_r($app->auto_reversal_timers, true));
		foreach($app->auto_reversal_timers as $transID => $timer) {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'TransID:'.$transID.' timer id:'.$timer->id."\n\t\ttimer lastTimeout:".$timer->lastTimeout."\n\t\t timer finished:".$timer->finished);
		}
		// clear any existing timer for this trans id
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' About to cancel existing timer for ID:'.$id.' and re-create it');
		$app->clearAutoReversalTimer($id);
		Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Adding an auto-reversal timer for transID:'.$id);
		$app->auto_reversal_timers[$id] = new Timer(function() use ($app, $id){
			Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Auto reversal timer fired for transID:'.$id);
			//$app->createAutoReversalTrans($id);
			$app->checkForReversalTrans($id);
			$app->createAutoReversalTimer($app, $id);
		}, 1e6 * $app->auto_reversal_timeout);
	}
	
	/**
	 * clearAutoReversalTimer() - clear an auto reversal timer 
	 * @param Int $id - the DB record id of the timer to cancel
	 */
	public function clearAutoReversalTimer($id) {
		Vendor::logger(Vendor::LOG_LEVEL_INFO, __METHOD__.' transID:'.$id);
		if (array_key_exists($id, $this->auto_reversal_timers)) {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Canceling auto reversal timer for transID:'.$id);
			$this->auto_reversal_timers[$id]->cancel();
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Removing auto reversal timer for transID:'.$id);
			unset($this->auto_reversal_timers[$id]);
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Reversal Timer not found for $id: '.$id.'! timers array contains: '.count($this->auto_reversal_timers).' elements');
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Reversal Timers:'.print_r(array_keys($this->auto_reversal_timers), true));
		}
	}

	/**
	 * checkForReversalTRans() - find if the given transID has a timeout reversa
	 * transaction already created; resend if found; create and send if not found
	 * @param Int $id - the DB record of the trans to check for a reversal
	 * @return null
	 */
	public function checkForReversalTrans($id){
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.':'.__LINE__.' $id:'.print_r($id, true));
		$app = $this;
		// find our $req from the $app->pending_requests array
		if (array_key_exists($id, $app->pending_requests)) {
			$req = $this->pending_requests[$id];
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_ERROR, ' Unable to find the $req object for transID:'.$id);
			return;
		}
		$q = SQL::findReversalTransQuery($id, $this->trans_table);
		$app->createJobFromQuery($app, $req, $id.'-check_for_reversal_trans', $q, false, 
				function($result, $q) use($app, $req, $id){
			// check if we have a reversal trans; if so, we use its id to send
			// the next message. If not, we create a new reversal trans record
			//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.':'.__LINE__.' $q:'.print_r($q, true));
			//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.':'.__LINE__.' $result:'.print_r($result, true));
			if (count($result) > 0) {
				$reversal_id = $result[0]['id'];
				// we found our existing reversal transaction; submit it again
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, ' Attempting to send transaction with ID:'.$reversal_id);
				$app->createISOandSend($app, $req, $reversal_id);
			} else {
				// we need to create a new reversal transaction
				$app->createAutoReversalTrans($id, $req);
			}
		});
	}
	
	/**
	 * createReversalTransJob() - pull info on a transaction and create a new
	 * transaction to reverse it
	 * @param int $id - DB record id of the id we will reverse
	 * @param HTTPRequest $req - The HTTP Request associated with this transaction
	 */
	public function createAutoReversalTrans($id, $req) {
		$app = $this;
		$q = SQL::singleTransDetailQuery($id, $this->trans_table);
		$app->createJobFromQuery($app, $req, $id.'-reversal_trans', $q, false, function($result) use($app, $req, $id){
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'We are inside the createReversalTransJob() job callback!');
			//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Need to build a reversal DB record from data:'.print_r($result, true));
			$orig_tr = $result[0];
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Reversal trans original receipt_number:'.$orig_tr['receipt_number']);
			$q = SQL::buildQueryForReversal($result[0], ISO8583Trans::TRANS_TYPE_TIMEOUT_REVERSAL, $this->trans_table);
			$app->createJobFromQuery($app, $req, $id.'-reversal_iso', $q, false, function($result) use($app, $req, $orig_tr){
				// get our queries to fill the extra tables
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Inside the reversal_iso callback using $result:"'.print_r($result, true).'"');
				$queries = SQL::buildQueriesForReversal($orig_tr, $result, $app);

				// execute our queries
				while(count($queries) > 0){
					$q = array_shift($queries);
					$app->executeQuery($app, $q);
				}

				// create our ISO8583 and send it
				$app->createISOandSend($app, $req, $result);
			});
		});
	}
	
	/**
	 * createRefundTransaction() - pass in a record id and create and send a ISO8583 to refund it
	 * @param Int $orig_trans_id - DB record id that we want to refund
	 * @return Null
	 */
	public function createRefundTransaction($orig_trans_id) {
			$app = $this;

			// get our query to retrieve the original transaction info
			$q = SQL::originalTransForRefund($orig_trans_id);
			$app->executeQuery($app, $q, function($result) use($app, $orig_trans_id){
				// check our response_code; do not refund a declined transaction
				if ($result[0]['response_code']!== '00') {
					Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'Transaction ('.$orig_trans_id.') not refunded because it was declined');
					return;
				}
				$orig_trans_row = $result[0];

				$q = "UPDATE fd_draft_data SET refunded = true WHERE id = {$result[0]['id']}";
				$app->executeQuery($app, $q, function($result) use($app, $q, $orig_trans_row){
					if ($result !== 1) {
						Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'Incorrect number of rows updated for query:'.$q);
						return;
					}

					$q = SQL::buildQueryForRefund($orig_trans_row, $this->trans_table, 'RECURRING_BILLING');
					$app->executeQuery($app, $q, function($result) use($app,$q){
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG,'Refund transaction created. About to send!');
						$app->createISOandSend($app, null, $result, null, null);
					});
				});

			});
        }

	/**
	 * createJobFromQuery() - execute the given query and store the results in
	 * the request->job with given name
	 * @param RealTimeTrans $app - the main appInstance to get the MySQL Conn from
	 * @param RealTimeTransRequest $req - the HTTP Request we are working with
	 * @param String $job_name - name of the job to store the results in
	 * @param String $q - query to execute on the DB
	 * @param Bool $wake - should we wake our $req when this job completes
	 * @param Callable $cb - any extra function that should execute when this job completes
	 * @return null
	 */
	public function createJobFromQuery($app, $req, $job_name, $q, $wake, $cb = null){
		// if our $req is null, then we just log an error; if $req is an int
		// then we search for the $req object in $app->pending_requests
		if ($req === null){
			Vendor::logger(Vendor::LOG_LEVEL_INFO, __METHOD__.' $req object is null!');
			// 2013-05-02 we no longer create the job on our $req object. so this
			// is not a fatal error
			//return;
		}
		$job = $app->job;
		// check if our job is already completed. There is no way to restart
		// a job once the master object has completed.
		if ($job->hasCompleted()) {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.': creating a new ComplexJob. The existing one has completed');
			$job = new ComplexJob();
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Adding job:'.$job_name.' to $job object of type:'.get_class($job));
		$job_result = $app->job->addJob($job_name, function($name, $job) use ($app, $req, $q, $wake, $cb){
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Inside the createJobFromQuery()->addJob('.$name.') callback!');
			// get our sql connection
			$sqlConnResult = $app->sql->getConnection(function($sql, $success) use($app, $req, $q, $job, $name, $cb, $wake){
				if (!$success) {
					Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Error getting SQL connection. '.__METHOD__.' error:'.$sql->errmsg);
				} else {
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Got sql connection. $sql:'.print_r($sql, true));
				}
				$sql->query($q, function($sql, $success) use($app, $req, $q, $job, $name, $cb, $wake){
					// check if we should log all queries
					if ($app::$log_queries) {
						fwrite($app->qfile, $q."\n\n");
					}
					if (!$success) {
						Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'executing query:'.$q.', error:'.$sql->errmsg);
						return $job->setResult($name, 'ERROR with Query! Query:'.$q.', error:'.$sql->errmsg);
					} 
					
					// create a $result variable. We set it to real values if our query succeeds
					$result = self::determineSQLResult($q, $sql);

					// call any callback and pass it the $result from this job
					if(is_callable($cb)) {
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.':'.__LINE__.' About to call_user_func() and pass $result'); //:'.print_r($result, true));
						call_user_func($cb, $result, $q, $sql);
					}
					
					// we only set a result for this job if told to wake the request
					// this prevents the request from waking before all queries have
					// completed
					if ($wake) {
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Attempting to call $req->wakeup()!');
						$job->setResult($name, $result);
						if (is_object($req)) {
							$req->wakeup();
						}
					}else {
						Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'job_name:'.$name.' not set to wake $req!');
					}
				});
			}); // end of sql->getConnection callback
			if($sqlConnResult === false) {
				Vendor::logger(Vendor::LOG_LEVEL_CRITICAL, 'Failed to get SQL connection! query:'.$q);
			} else {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.": \$sql_ConnResult:".print_r($sqlConnResult, true));
			}
			
			
		}); // end of job callback
		
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'addJob('.$job_name.') result:'.$job_result);
		if($job_result === false) {
			Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Job('.$job_name.') returned false! Retrying with updated name');
			$app->createJobFromQuery($app, $req, $job_name.'2', $q, $wake, $cb);
		} else {
			// save our job_name in the $req object
			if (!is_null($req)){
				$req->last_job_name = $job_name;
			}
			// 2013-04-19 The jobs are now added to the app instead of the request.
			// we always execute our job immediately
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.': about to execute our job');
			$job();
			
		}
	}
	
	/**
	 * updateClientWS() - send an update to the remote client over websocket
	 * @param Closure $ws - the callback that has access to the client websocket
	 * @param ISO8583Trans $msg - the message object received from the remote vendor
	 */
	public function updateClientWS($ws, $msg) {
		try {
			$resp = new VendorClientMessage();
			$resp->response = "This is response text";
			$resp->trans_id = $msg->original_trans_id;
			if ($msg->dataExistsForBit(38)) {
				$resp->auth_iden_resp = $msg->getDataForBit(38);
			}
			if ($msg->dataExistsForBit(39)) {
				$resp->response_code = $msg->getDataForBit(39);
			}
			$ws->client->sendFrame(json_encode($resp), 'STRING');
		}catch(Exception $e) {
			Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Exception caught trying to create object for websocket! Exception:'.$e);
		}
	}
	
	/**
	 * executeQuery() - get a MySQL connection and execute a query against the DB
	 * @param Vendor $app - our main application object
	 * @param String $query - the SQL to execute against the DB
	 * @param Callable $cb - function to execute after the query is complete
	 * @return Bool - was the sql connection successful
	 */
	public function executeQuery($app, $query, $cb=null) {
		// get a connection to our MySQL database
		$sql_conn = $app->sql->getConnection(function($sql, $success) use ($query, $app, $cb) {
			// the MySQL getConnection callback is passed the MySQL object and a boolean
			// success flag

			if (!$success) {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'getConnection failed in '.__METHOD__);
			} else {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'getConnection succeeded in '.__METHOD__);
			}
			if (self::$log_queries && $app->qfile !== false) {
				fwrite($app->qfile, "$query\n\n");
			}

			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'About to call $sql->query() on $sql:'.get_class($sql));
			// execute a query on our sql object
			$sql->query($query, function($sql, $success) use ($query, $cb) {
				if (!$success) {
					Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Error executing query:'.$query);
					return;
				}else {
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.': successfully ran query:'.$query);
				}
				$result = self::determineSQLResult($query, $sql);
				// execute our callback or return the results of this query
				if (is_callable($cb)) {
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.': calling user provided $cb');
					call_user_func($cb, $result);
				} else {
					return $sql;
				}
			});			
				
		});
		Vendor::logger(Vendor::LOG_LEVEL_INFO, __METHOD__.': $sql_conn:'.$sql_conn);
		if ($sql_conn === false) {
			// return false if the SQL connection fails
			Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'sql_conn failed. Query:'.$query);
			return $sql_conn;
		}
	}
	
	/**
	 * updateTransInDB() - save the response from the remote vendor for this message
	 * @param ISO8583Trans $msg - the message returned from the remote vendor
	 * @param self $app - the appInstance we are running under
	 * @param HTTPRequest $req - the HTTP Request (if any)
	 * @param Callable $cb - function to execute on completion
	 */
	public function updateTransInDB($msg, $app, $req = null, $cb = null) {
		// there are several queries required to update the different tables
		// $queries is an array of queries
		$queries = SQL::buildQueryForTransUpdate($msg, $app);
		
		while (count($queries) > 0 ) {
			$query = array_shift($queries);
			$sql = $app->executeQuery($app, $query);
			//Vendor::log(Vendor::LOG_LEVEL_DEBUG, '$sql returned from executeQuery():'.print_r($sql, true));
		}
		
		if (is_callable($cb)) {
			call_user_func($cb);
		}		
	}
	
	/**
	 * checkOutboundQueue() - check outbound queue for message and send
	 * @param Vendor $app
	 * @param Closure $cb - a function to execute once the queue is checked
	 */
	public function checkOutboundQueue($app, $cb = null){
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' running!');
		// if there any items in the queue then send one off 
		if (count($app->tq) > 0) {
			// item exists in queue; check connection
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' '.count($app->tq).' items exist in queue. Attempting to send one!');
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'About to start while() trying to get connection');
			
			while (!$app->vendorconn->connected) {
				$app->connect();
			}
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'while() loop completed! Connected state:'.$app->vendorconn->isConnected());
			// check connection state and if established send message
			if ($app->vendorconn->connected) {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Attempting to send data to '.$app->vendorconn->getHost());
				$msg = $app->tq->dequeue();
				// varify this is not a dupe trans
				if (!$this->checkDupeTrans($msg->ISO8583)) {
					$app->vendorconn->sendData($msg->getTCPMessage(), $cb);
				}
			}
			
//			// execute our callback (if any)
//			if (isset($cb) && is_callable($cb)) {
//				Vendor::logger(Vendor::LOG_LEVEL_DEBUG,' About to call user function at '.__FILE__.':'.__LINE__);
//				call_user_func($cb);
//			} else {
//				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.': $cb not set or not callable!');
//			}
		}
	}
	
	/**
	 * checkDupeTrans() - compare this transaction ID to recent ones to prevent
	 * duplicate transactions from gogin through
	 * @param String $tcp_data - the binary TCP data to send
	 * @return boolean
	 */
	public function checkDupeTrans($iso) {
		$TID = $iso->getDataForBit(41, true);
		$receipt_number = $iso->getDataForBit(11, true);
		$retrieval_reference_number = ltrim($iso->getDataForBit(37, true), '0');
		$MTI = $iso->getMTI(true);
		$acquirer_reference_data = substr($iso->getDataForBit(31, true),-1);
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.'$acquirer_reference_data (HEX):"'.bin2hex($acquirer_reference_data).'" is of type:'.Vendor::get_type($acquirer_reference_data));
		// we only check MTI === 0100; others are followup messages
		if ($MTI !== '0100') {
			Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Trans ('.$receipt_number.') does not have MTI of 0100. Sending without check. MTI:'.$MTI);
			return false;
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Trans ('.$receipt_number.') has MTI:'.$MTI.' Checking against recent transactions.');
		}
		// find if this transaction has been sent
		// ensure this TID is an array in $recent_trans
		if (!array_key_exists($TID, $this->recent_trans)) {
			$this->recent_trans[$TID] = array('auth'=>array(), 'capture'=>array());
//			$this->recent_trans[$TID]['auth']=array();
//			$this->recent_trans[$TID]['capture']=array();
			Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Adding TID ('.$TID.') to recent_trans array');
//			Vendor::logger(Vendor::LOG_LEVEL_INFO, '$this->recent_trans:'.print_r($this->recent_trans, true));
		}
		// we only store the last 50000 transactions for each TID
		if (count($this->recent_trans[$TID]['auth']) >= 50000) {
			Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Removing 1 transaction from auth list for TID:'.$TID);
			array_shift($this->recent_trans[$TID]['auth']);
		}
		if (count($this->recent_trans[$TID]['capture']) >= 50000) {
			Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Removing 1 transaction from capture list for TID:'.$TID);
			array_shift($this->recent_trans[$TID]['capture']);
		}
		if ( $acquirer_reference_data === 0 
				|| $acquirer_reference_data === 1
				|| $acquirer_reference_data === '0'
				|| $acquirer_reference_data === '1') {
			Vendor::logger(Vendor::LOG_LEVEL_INFO, '$this->recent_trans[$TID][auth] is of type:'.Vendor::get_type($this->recent_trans[$TID]['auth']));
			//Vendor::logger(Vendor::LOG_LEVEL_INFO, '$this->recent_trans[$TID][auth]:'.print_r($this->recent_trans[$TID]['auth'], true));
			if (in_array($receipt_number, $this->recent_trans[$TID]['auth'])){
				Vendor::logger(Vendor::LOG_LEVEL_CRITICAL, 'Transaction ('.$receipt_number.') has already been authorized!');
				//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this->recent_trans:'.print_r($this->recent_trans, true));
				return true;
			} else {
				$this->recent_trans[$TID]['auth'][] = $receipt_number;
				Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Adding trans ('.$receipt_number.') to recent authorizations array');
			}
		} else if ($acquirer_reference_data === 2 || $acquirer_reference_data === '2'){
			if (in_array($receipt_number, $this->recent_trans[$TID]['capture'])){
				Vendor::logger(Vendor::LOG_LEVEL_CRITICAL, 'Transaction ('.$receipt_number.') has already been captured!');
				//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this->recent_trans:'.print_r($this->recent_trans, true));
				return true;
			} else {
				$this->recent_trans[$TID]['capture'][] = $receipt_number;
				Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Adding trans ('.$receipt_number.') to recent capture array');
			}
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'checkDupeTrans() $acquirer_reference_data not (string) 0,1,2!');
			switch($acquirer_reference_data){
				case 0:
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$acquirer_reference_data is 0 (integer)');
					break;
				case 1:
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$acquirer_reference_data is 1 (integer)');
					break;
				case 2:
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$acquirer_reference_data is 2 (integer)');
					break;
				case "0":
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$acquirer_reference_data is 0 (string)');
					break;
				case "1":
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$acquirer_reference_data is 1 (string)');
					break;
				case "2":
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$acquirer_reference_data is 2 (string)');
					break;
				default:
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$acquirer_reference_data is unknown!');
			}
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.'$acquirer_reference_data:"'.$acquirer_reference_data.'" is of type:'.Vendor::get_type($acquirer_reference_data));
			//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this->recent_trans:'.print_r($this->recent_trans, true));
		}
			
		Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Trans id:'.$retrieval_reference_number.', receipt_num:'.$receipt_number.' should be sent!');
		return false;
	}
	
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' running');
		return new VendorRequest($this, $upstream, $req);
	}
	
	/**
	 * processDraft() - pull a single transaction from the DB and send it off
	 * this does not use the createJobFromQuery() method because it pulls the
	 * same query many times. The job name would end up being too long
	 */
	public function processDraft(){
		Vendor::logger(Vendor::LOG_LEVEL_INFO,__METHOD__.' called');
		$app = $this;
		if ($app->allow_draft_processing === true) {
			$today = date('Y-m-d');
			$q = SQL::buildSubmitDraftSQL($today);
			$app->executeQuery($app, $q, function($result) use($app, $today){
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Executing the call back for processDraft() job');

				if (count($result) > 0) {
					while(count($result) > 1) {
						$trans_row = array_shift($result);
						$app->createISOandSend($app, null, $trans_row['id'], null, null, false, null);
					}
					// send off the first transaction
					$trans_row = array_shift($result);
					$app->createISOandSend($app, null, $trans_row['id'], null, null, false, function() use ($app){
//						Vendor::logger(Vendor::LOG_LEVEL_DEBUG,'Inside the createISOandSend() CB from processDraft()');
						$app->processDraft();
					});
				} else {
					Vendor::logger(Vendor::LOG_LEVEL_NOTICE, get_class().': no more transactions to process for '.$today.' in '.__METHOD__.':'.__LINE__);
				}
			});			
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_ERROR, get_class().': not continuing draft processing due to $app->allow_draft_processing !== true');
			return;
		}

	}
	
	/**
	 * settleTransactions() - start the process to settle any transactions
	 * @param Closure $cb - a callback to execute once this method completes
	 * @return type
	 */
	public function settleTransactions($stcb = null){
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' called');
		$app = $this;
		if ($app->allow_draft_processing === true) {
			$q = SQL::viewNonSettledTrans($app->trans_table);
			$app->executeQuery($app, $q, function($result) use($app, $q, $stcb){
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Executing the call back for settleTransactions() job');
				
				if (count($result) > 0) {
					// send off the first settlement
					$trans_row = array_shift($result);
					Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Attempting to settle trans ('.$trans_row['id'].')');
					$app->createISOandSend($app, null, $trans_row['id'], null, null, true, function () use ($app, $stcb){
						Vendor::logger(Vendor::LOG_LEVEL_DEBUG,'Inside the createISOandSend() CB from settleTransactions()');
						$app->settleTransactions($stcb);
					});
				} else {
					Vendor::logger(Vendor::LOG_LEVEL_INFO, get_class().': no trasnactions found to settle!');
					if (is_callable($stcb)) {
						call_user_func($stcb);
					}
				}
			});
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_ERROR, get_class().': not settling transactions due to $app->allow_draft_processing !== true');
		}
		
	}
	
	/**
	 * createTransaction() - create and send a transaction over TCP
	 * @param int $msg_id - the database id of the transaction to create
	 * @param VendorWebSocketRoute $ws_closure - the closure that handles websocket communication
	 * @param VendorRequest $req - the HTTP request that initiated this
	 * @return Null
	 */
	public function createMessage($msg_id, $ws_closure, $req) {
		$app = $this;
		Vendor::logger(Vendor::LOG_LEVEL_NOTICE, __METHOD__.' called with argument:'.$msg_id);
		//Vendor::log(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' callback is:'.print_r($cb, true));
		
		// save our closure in appInstance so we can send data back to client 
		// over web socket
		$this->ws = $ws_closure;
		
		$this->createISOandSend($app, $req, $msg_id);		
	}
	
	/**
	 * createNewTrans() - this will create a new transaction using an existing
	 * transaction and a second response message.
	 * @param ISO8583Trans $iso - the ISO8583 response message
	 * @param Array $tr - DB record of the original transaction
	 * @param String $msg - binary data as received from the vendor
	 * @return Int - the new DB record ID of the duplicated transaction
	 */
	protected function createNewTrans($iso, $tr, $msg) {
		$app = $this;
		$q = SQL::buildQueryForTransInsert($iso, $this->trans_table, $tr);
		$executeResult = $app->executeQuery($this, $q, function($result) use($app, $tr, $msg){
			Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Inside the createNewTrans()->executeQuery() CB! $result:'.$result);
			//return $result;
			$app->processReceivedData($msg, $app, true, $result);
		});
		vendor::logger(Vendor::LOG_LEVEL_INFO, __METHOD__.': $executeResult:'.$executeResult);
		return $executeResult;
		
	}
	
	/**
	 * setBatchAsTransmitted() - update the batch in the DB to set it as transmitted
	 * @param int[] $ids - array of batch_ids to set as transmitted
	 * @param ComplexJob $job
	 * @return bool
	 */
	public function setBatchAsTransmitted($ids) {
		$app = $this;
		
		// create a job to do our query
		$job = $this->job = new ComplexJob();
		
		// register the trans_row job. This is where the main data to create ISO8583
		// will come from
		$job->addJob('set_batch_as_transmitted', function($name, $job) use($app, $ids){
			// the addJob callback gets called with the job name and job object as callbacks
			// we also need access to our appInstance and the ids for our query
			
			// get a connection to our MySQL database
			$app->sql->getConnection(function($sql, $success) use ($name, $job, $app, $ids) {
				// the MySQL getConnection callback is passed the MySQL object and a boolean
				// success flag
				
				if (!$success) {
					Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'getConnection failed in '.__METHOD__);
					return $job->setResult($name, 'Error connecting to MySQL Server');
				}
				
				// execute a query on our sql object
				$id_string = join(',', $ids);
				$query = "UPDATE cc_draft_log SET transmit_dt=NOW() WHERE id IN ({$id_string})";
				//Vendor::log(Vendor::LOG_LEVEL_DEBUG, 'About to execute query:'.$query);
				//$query = "{$app->trans_query}{$msg_id}";
				$sql->query($query, function($sql, $success) use ($job, $name, $app, $query) {
					if (!$success) {
						Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Error executing query:'.$query);
						return $job->setResult($name, 'Error with Query! Query:'.$query.', error:'.$sql->errmsg);
					}
					
					// if we get here then our query was successful. Nothing left to do
					// 
					$job->setResult($name, true);
					
				});
			
			});
		});
		
		// run our job to actually connect to MySQL and execute the query
		$job();
	}
	
	public function saveStats(){
		$auth_count = 0;
		$capture_count = 0;
		foreach($this->recent_trans as $TID=>$trans){
			$auth_count += count($trans['auth']);
			$capture_count += count($trans['capture']);
		}
		$stat_log = date('H:i:s').",".$auth_count."\n";
		Vendor::logger(Vendor::LOG_LEVEL_INFO, "saving to stat log: {$stat_log}");
		fwrite($this->lfile, $stat_log);
	}
	
	// <editor-fold defaultstate="collapsed" desc="Static Methods">
	/**
	 * decrypt_data() - decrypt an encrypted string that is also base64 encoded 
	 * @param String $data - encrypted data
	 * @return string - plain text data
	 */
	public static function decrypt_data($data){
		$error = array();
		$error['error_msg'] = '';
		$error['private_key'] = self::PRIVATE_KEY;
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Attempting to decrypt data with length:'.strlen($data));
		
		$priv_key = openssl_get_privatekey(self::PRIVATE_KEY);
		if (!$priv_key)
		{
			// error opening private key
			$error['error_msg'] = 'Unable to open private key ('.self::PRIVATE_KEY.')!';
			return $error;
		}

		$bdata = base64_decode($data);
		if ($bdata === false || strlen($bdata) === 0)
		{
			$error['error_msg'] = "Error with base64_decode({$data})!";
			return $error;
		}
		$decrypted = '';
		$result = openssl_private_decrypt($bdata, $decrypted, $priv_key);
		if ($result)
		{
			// the real data is padded with '::::' plus a random string
			// strip off the extra data and return our plain text
			//echo "\$pos: $pos\n";
			return substr($decrypted, 0, strpos($decrypted, '::::'));
		}
		else
		{
			//error_log('Error decrypting! $eccnum:'.$eccnum);
			$error['error_msg'] = 'Error decrypting $eccnum:'.$data.
					", \$decrypted:".$decrypted.", strlen(\$bdata):".
					strlen($bdata);
			return $error;
		}
	}
	
	/**
	 * attachEventHandlers() - attach handlers to the client connection to handle
	 * disconnection, processing of received data, and sending of data
	 * @param VendorClientConnection $conn
	 * @param Vendor $app
	 */
	public static function attachEventHandlers($conn, $app) {
		// add event handlers to process incoming messages and reconnect
		// automatically when the connection disconnects
		$conn->addEventHandler('data_recvd', function($msg) use ($conn, $app) {
			$app->processReceivedData($msg, $conn, $app);

		});

		// add an event handler to reconnect when the connection ends
		// this also will try a different IP if more than one is defined
		$conn->addEventHandler('disconnect', function($failed_ip, $failed_port) use ($app) {
			//$app->vendorconn->connected = false;

			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, $failed_ip.':'.$failed_port.' disconnected. Attempting to reconnect');
			$srv_list = explode(',',$app->vendorclient->config->servers->value);
			$new_ip = array_shift($srv_list);
			if ($new_ip === $failed_ip) {
				$new_ip = array_shift($srv_list);
			}
			// recall the connect function using the other IP
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'About to attempt connection to: '.$new_ip.':'.$failed_port);
			$app->connect("tcp://{$new_ip}:{$failed_port}");
		});

		// add a handler for when data is sent
		$conn->addEventHandler('data_sent', function($data_length) use ($app){
			// this is really just for testing
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, get_class($app). ' data_sent callback fired! Sent '.$data_length.' bytes.');
		});
		
		// add a handler for the keep-alive timer firing; this will check if we should
		// auto submit the draft
		$conn->addEventHandler('keep-alive_timeout', function() use ($app){
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'keep-alive_timeout event handler firing');
			// check if should auto submit the draft for today
			if ($app->auto_submit_draft && date('G') > $app->auto_submit_time) {
				// we need to check for transactions
				Vendor::logger(Vendor::LOG_LEVEL_INFO,get_class($app).': auto checking for draft transactions');
				$app->processDraft();
			} else {
				Vendor::logger(Vendor::LOG_LEVEL_INFO, get_class($app).': not attempting to process draft. $this->auto_submit_draft:'.$app->auto_submit_draft.', $this->auto_submit_time:'.$app->auto_submit_time);
			}
			
			// this handles the auto submit for settlement transactions
			if ($app->auto_settle_transactions && date('G') >= $app->auto_settle_time) {
				$app->settleTransactions();
			} else {
				Vendor::logger(Vendor::LOG_LEVEL_INFO, get_class($app).': not attempting to settle transactions. $this->auto_settle_transactions:'.$app->auto_settle_transactions.', $this->auto_settle_time:'.$app->auto_settle_time);
			}
		});
	}
	
	/**
	 * get_type() - Return the type of the passed $var. Useful for debugging.
	 * @param Any $var
	 * @return string
	 */
	public static function get_type($var) { 
		if(is_object($var)) 
			return get_class($var); 
		if(is_null($var)) 
			return 'null'; 
		if(is_string($var)) 
			return 'string'; 
		if(is_array($var)) 
			return 'array'; 
		if(is_int($var)) 
			return 'integer'; 
		if(is_bool($var)) 
			return 'boolean'; 
		if(is_float($var)) 
			return 'float'; 
		if(is_resource($var)) 
			return 'resource'; 
		//throw new NotImplementedException(); 
		return 'unknown'; 
	} 
	
	public static function logger($level, $msg) {
		// if the level of this message is below our stated level then log;
		// otherwise we discard
		if ($level <= self::$log_level) {
			Daemon::log(self::logLevelName($level).$msg);
		}
	}
	
	public static function logLevelName($level) {
		switch($level) {
			case 9:
				return '[DEBUG] ';
				break;
			case 8:
				return '[INFO] ';
				break;
			case 7:
				return '[NOTICE] ';
				break;
			case 6:
				return '[WARNING] ';
				break;
			case 5:
				return '[ERROR] ';
				break;
			case 4:
				return '[CRITICAL] ';
				break;
			case 3:
				return '[ALERT] ';
				break;
			case 2:
				return '[EMERGENCY] ';
				break;
			deafult: 
				return '[UNKNOWN LVL ('.$level.')] ';
		}
	}
	
	/**
	 * determineSQLResult() - look at the query and determine what should be returned
	 * @param String $q - the query to parse
	 * @param MySqlClient $sql - the result from the query
	 * @return Mixed
	 */
	public static function determineSQLResult($q, $sql) {
		$result = '';
		// evaluate whether we have an INSERT or SELECT query
		// set our $result based on the query type
		$qtype = substr($q,0,strpos($q, ' '));
		switch(trim(strtoupper($qtype))){
			case 'INSERT':
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' using the insertId value for result!');
				//Vendor::log(Vendor::LOG_LEVEL_DEBUG, __METHOD__.'$sql->insertId:'.print_r($sql->insertId, true).' q:'.$q);
				$result = $sql->insertId;
				break;
			case 'SELECT':
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' using the resultRows value for result!');
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' result contains '.count($sql->resultRows). ' entries');
				//Vendor::log(Vendor::LOG_LEVEL_DEBUG, __METHOD__.'$sql->resultRows:'.print_r($sql->resultRows, true));
				$result = $sql->resultRows;
				break;
			case 'UPDATE':
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' using the affectedRows value for result!');
				//Vendor::log(Vendor::LOG_LEVEL_DEBUG, __METHOD__.'$sql->affectedRows:'.print_r($sql->affectedRows, true).' q:'.$q);
				$result = $sql->affectedRows;
				break;
			default:
				Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'Unknown query type:'.$qtype);
				$result = $sql->errmsg;
		}
		return $result;
	}
	
	// </editor-fold>
	
}
?>
