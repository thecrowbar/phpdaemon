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
    /**
     * Flag that enables/disables extra log output
     * @var bool
     */
	public static $debug = true;
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
	 * The DB table that holds the transactions for this appInstance
	 *@var String
	 */
	public $trans_table_name = 'fd_draft_data';
	
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
	
	/**
	 * Flag to indicate if queries should be logged instead of executed
	 * @var bool
	 */
	public static $log_queries = true;
	
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
	
	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * Uncomment and return array with your default options
	 * @return array|false
	 */
//	protected function getConfigDefaults() {
//		return array(
//			'url' => 'tcp://127.0.0.1:12345'
//		);
//	}
	
	/**
	 * First method called when a new object is created.
	 */
	public function init() {
		Daemon::log(__METHOD__.' start');
		// start xdebug trace of our code
		//xdebug_start_trace('/opt/phpdaemon/log/Vendor.trace');
		
		// give ourself plenty of RAM
		ini_set('memory_limit', '900M');
		
		// set our config values for easier access
		$this->vendorhost = $this->config->vendorhosts->value;
		$this->vendorport = $this->config->vendorport->value;
		
		// get an intial connection
		$svr_config = new Daemon_ConfigSection(array('servers'=>"{$this->vendorhost}", 'port'=>"{$this->vendorport}"));
		$this->vendorclient = VendorClient::getInstance($svr_config);
		
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
//		Daemon::log('$this->sql:'.print_r($this->sql, true));
		
		// create a connection to the MySQL server for use later
		$this->sql_conn = $this->sql->getConnection();
		
		// initialize our queue to hold outgoing transactions
		$this->tq = new SplQueue();
		
		// setup our log file to save queries; if flag is set
		if (self::$log_queries) {
			$this->qfile = fopen($this->query_log_file, 'a+');
			if ($this->qfile === false) {
				Daemon::log('ERROR**ERROR Unable to open the query log file!');
			}else {
				fwrite($this->qfile, __CLASS__.' starting up at '.date('Y-m-d H:i:s'));
			}
		}
		Daemon::log(__METHOD__.' end');
	}
	
	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		Daemon::log(__METHOD__.' start');
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
		
		// start a timer to check the outbound queue every 90 seconds
		$app = $this;
		$this->keepaliveTimer = setTimeout(function($timer) use($app) {
			Daemon::log(__METHOD__.' timer callback firing');
			$app->checkOutboundQueue($app);
		}, 1e6 * 90);
		Daemon::log(__METHOD__.' end');
	}
	
	/**
	 * Establish a connection to the remote vendor and start a timer to send 
	 * keepalive messages
	 */
	public function connect() {
		Daemon::log(__METHOD__.' running');
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
					if (self::$debug) {
						Daemon::log(__METHOD__.' callback added!');
					}
				} else {
					Daemon::log($arg.' is not an object and not callable');
				}
			}
		}
		if (self::$debug) {
			Daemon::log('Found '.count($callbacks).' callbacks for '.__METHOD__);
		}
		
		// check our connection, if it is connected then we run callbacks and return
		if (!is_object($app)) {
			Daemon::log('$app is not an object. FATAL! $this:'.print_r($this, true));
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
				Daemon::log(__METHOD__.':'.__LINE__.': url:'.$url);
				$app->vendorclient->getConnection( $url, function ($conn) use ($app, $callbacks) {
					$app->vendorconn = $conn;
					if ($conn->isConnected()) {
						if (self::$debug) {
							Daemon::log(get_class($app).' connected at '.$conn->hostReal.':'.$conn->port);
						}

						self::attachEventHandlers($conn, $app);

						// call our callback functions
						Daemon::log('Vendor->connect()->closure(), about to call '.count($callbacks).' callbacks');
						foreach($callbacks as $cb) {
							call_user_func($cb);
						}
					}
					else {
						Daemon::log('VendorClient: unable to connect ('.$conn->hostReal.':'.$conn->port.')');
					}
				});
			} else {
				Daemon::log(__METHOD__.':'.__LINE__.': using an auto selected value from the server list');
				// use an auto selected value from the servers list
				$obj = $app->vendorclient->getConnection( function ($conn) use ($app, $callbacks) {
					$app->vendorconn = $conn;
					if ($conn->isConnected()) {
						if (self::$debug) {
							Daemon::log(get_class($app).' connected at '.$conn->getHost().':'.$conn->getPort());
						}

						self::attachEventHandlers($conn, $app);

						// call our callback functions
						Daemon::log('Vendor->connect()->closure(), about to call '.count($callbacks).' callbacks');
						foreach($callbacks as $cb) {
							call_user_func($cb);
						}
					}
					else {
						Daemon::log('VendorClient: unable to connect ('.$conn->getHost().':'.$conn->getPort().')');
					}
				});
				if (is_object($obj) && !$obj->isConnected()) {
					// connection failed. try other IP
					$srv_list = explode(',',$app->vendorclient->config->servers->value);
					Daemon::log('Servers list:'.print_r($srv_list, true));
				} else if($obj === false) {
					Daemon::log('Unable to connect! '.__METHOD__.':'.__LINE__);
				}
			}
		}
		Daemon::log(__METHOD__.' end reached');
	}
	
	/**
	 * processReceivedData() - process the data returned by the remote vendor
	 * @param byte[] $msg - the data received from the vendor
	 * @param VencodrClientConnection $conn - the remote connection
	 * @param Vendor $app - a link back to this object
	 */
	public function processReceivedData($msg, $conn, $app){
		$app = $this;
		// this closure handles the incoming data
		// VendorClientConnection has done basic sanity checks to ensure 
		// a complete message has made it through
		if (self::$debug) {
			//Daemon::log('data_recvd handler fired! Processing '.strlen($msg).' bytes of data.');
		}

		// $msg is a string of bytes from TCP socket convert it into an object
		try {
			$iso_msg = new ISO8583Trans();
			$iso_msg->addTCPMessage($msg);
			$msg = $iso_msg;
		} catch(Exception $e){
			Daemon::log('Exeception caught trying to recreate ISO8583 from TCP data! Exception:'.$e);
			return;
		}
		if ($msg->msg_type === ISO8583::MSG_TYPE_AUTH_RESP 
				|| $msg->msg_type === ISO8583::MSG_TYPE_REV_RESP) {
			// this is a repsone to one of our messages, we need some information from the database
			if (self::$debug) {
				Daemon::log('Processing an AUTH_RESP or REV_RESP message');
			}
			
			// we first get the DB record id of the transaction and then update
			// several tables with data from the response
			$q = SQL::buildQueryForOriginalTrans($msg, $app);
			$app->createJobFromQuery($app, null, 'trans_orig_rec', $q, false, 
					function($result)use($app, $req, $job, $name, $msg){
				// process our response into a complete ISO8585 msg
				$msg = $app->createResponseMsg($msg, $result[0]);

				// at this point we have a complete transaction response
				// we need to update the original transaction in the database
				$app->updateTransInDB($msg, $app);

				// update the client over websocket that we received a response
				if (is_object($app->ws)) {
					$app->updateClientWS($app->ws, $msg);
				} 

				// output some basic debugging data to view
				Daemon::log($msg->getBasicDebugData());
				if (method_exists($msg, 'getBit63DebugData')){
					//Daemon::log($msg->getBit63DebugData());
				}
			}); // end of 'trans_orig_rec' callback
		} else {
			// we received a message that is not AUTH_RESP or REV_RESP; log it
			Daemon::log('NOTICE Received an ISO8583 message that is not 0110 or 0410! mti:'.$msg->getMTI());
		}
	}
	
	/**
	 * createISOandSend() - given a DB record id, create an ISO8583 message and
	 * submit
	 * @param RealTimeTrans $app - the appInstance we are communicating with
	 * @param RealTimeTransRequest $req - the HTTP Request for this transaction
	 * @param type $id - the DB record id to send
	 * @param type $track2 - the track2 data from a card swipe
	 * @param String $cvc - the Card Security Code from the credit card
	 * @param Closure $cb - additional callback to execute once the transaction is sent
	 */
	public function createISOandSend($app, $req, $id, $track2 = null, $cvc = null,
			$cb=null) {
		Daemon::log('We need to create an ISO8553 Object and send it! $id:'.$id);
		// if we have a $req object store it
		if (is_object($req)) {
			$app->pending_requests[$id] = $req;
		}
		$app->createJobFromQuery($app, $req, 'new_iso', SQL::singleTransDetailQuery($id), false,
				function($result) use($app, $req, $track2, $cvc, $cb){
			// first check if this transaction was already submitted
			if (is_array($result)) {
				// check the submit_dt field
				if ($result[0]['submit_dt'] !== '0000-00-00 00:00:00') {
					Daemon::log('ERROR Transaction '.$result[0]['id'].' was submitted to FD on '.$result[0]['submit_dt'].'!');
				} else {
					// update the submit_dt field for this transaction
					$q = SQL::updateSubmitDTQuery($result[0]['id']);
					$tr = $result[0];
					// add in our submit_dt, track2, and cvc data
					$tr['submit_dt'] = date('Y-m-d H:i:s');
					$tr['track2'] = $track2;
					$tr['table49'] = $cvc;
					$app->createJobFromQuery($app, $req, 'update_submit_dt', $q, false,
							function($result) use($app, $req, $tr, $q, $cb){
						Daemon::log('$result from update submit_dt:'.print_r($result, true));
						Daemon::log('Update query:'.$q);
						// check if we were able to update the transaction submit_dt value
						// if not, then we need to bail.
						if ($result !== 1) {
							Daemon::log('ERROR Failed to update the submit_dt value for transaction:'.$tr['id'].', error:'.$sql->errmsg);
						} else {
							try {
								$app->vendorMessage = new VendorMessage($tr);
								// send our iso
								// add the message to our queue
								$app->tq->enqueue($app->vendorMessage);
								//Daemon::log('NOTICE! Not Adding message to outbound queue');

								// send the data to the remote vendor
								Daemon::log('About to call Vendor->connect and pass in callback to checkOutboundQueue');
								$app->connect(function() use ($app){
									$app->checkOutboundQueue($app);
								});
								
								// execute our callback (if any)
								if (is_callable($cb)) {
									call_user_function($cb);
								}
							}catch(Exception $e){
								Daemon::log('ERROR Exception caught trying to create ISO8583! $e:'.$e);
							}
						}
					}); // end of createJobFromQuery
				}
			}
		}); // end of outer createJobFromQuery
	}
	
	
	/**
	 * Flush out a complete ISO8583Trans object from the returned message data
	 * @param ISO8583Trans $msg - the empty ISO8583 trans object
	 * @param Array $sr - the DB record from the original transaction.
	 * @return ISO8583Trans
	 * @throws Exception
	 */
	public function createResponseMsg($msg, $sr){
		// add data from the original trans into our new object
		try{
			if (self::$debug) {
				Daemon::log('Original trans id:'.$sr['id']);	
			}
			$msg->original_trans_id = $sr['id'];
			$msg->original_trans_amount = $sr['trans_amount'];
			if (self::$decrypt_data) {
				$data = self::decrypt_data($sr['pri_acct_no']);
				if (is_array($data)){
					throw new Exception('Error decrypting account data! Error:'.$data['error_msg']);
				}else {
					// save our encrypted account number
					$msg->encrypted_acct_no = $sr['pri_acct_no'];
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
						Daemon::log("User: {$sr['user_name']}, has unknown card type: {$sr['cc_type']}");
				}
			}

			if (self::$debug) {
				//Daemon::log('Finished adding data from original trans!');
			}
		}catch(Exception $e){
			Daemon::log('Exception caught trying to update transaction with original trans data! Exception:'. $e);
		}
		return $msg;
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
			Daemon::log('Exception caught trying to create object for websocket! Exception:'.$e);
		}
	}
	
	/**
	 * executeQuery() - get a MySQL connection and execute a query against the DB
	 * @param Vendor $app - our main application object
	 * @param String $query - the SQL to execute against the DB
	 * @param ISO8583Trans $msg - the transaction message object
	 * @param ComplexJob $job - object that stores our jobs
	 * @param String $name - job name to save results to
	 * @return bool - was the MySQL connection successful
	 * @note This does not return a status for the query itself
	 */
	public function executeQuery($app, $query) {
		// get a connection to our MySQL database
		return $app->sql->getConnection(function($sql, $success) use ($query, $app) {
			// the MySQL getConnection callback is passed the MySQL object and a boolean
			// success flag

			if (!$success) {
				if (self::$debug){
					Daemon::log('getConnection failed in '.__METHOD__);
				}
			}
			if (self::$log_queries && $app->qfile !== false) {
				//Daemon::log('QUERY: '.join(";\n",$queries).";\n");
				fwrite($app->qfile, "$query\n");
			}

			// execute a query on our sql object
			$sql->query($query, function($sql, $success) use ($query) {
				if (!$success) {
					Daemon::log('Error executing query:'.$query);
				}
				// return the results of this query
				return $sql;
			});			
				
		});
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
			//Daemon::log('$sql returned from executeQuery():'.print_r($sql, true));
		}
		
		if (is_callable($cb)) {
			call_user_func($cb);
		}		
	}
	
	/**
	 * checkOutboundQueue() - check outbound queue for message and send
	 * @param Vendor $app
	 */
	public function checkOutboundQueue($app){
		if (self::$debug) {
			Daemon::log(__METHOD__.' running!');
		}
		// if there any items in the queue then send one off 
		if (count($app->tq) > 0) {
			// item exists in queue; check connection
			if (self::$debug) {
				Daemon::log(__METHOD__.' '.count($app->tq).' items exist in queue. Attempting to send one!');
				Daemon::log('About to start while() trying to get connection');
			}
			
			while (!$app->vendorconn->connected) {
				$app->connect();
			}
			if (self::$debug) {
				Daemon::log('while() loop completed! Connected state:'.$app->vendorconn->connected);
			}
			// check connection state and if established send message
			if ($app->vendorconn->connected) {
				Daemon::log('Attempting to send data to '.$app->vendorconn->hostReal);
				$msg = $app->tq->dequeue();
				$app->vendorconn->sendData($msg->getTCPMessage());
			}
		}
	}
	
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		//Daemon::log(__METHOD__.' running');
		return new VendorRequest($this, $upstream, $req);
	}
	
	/**
	 * createTransaction() - create and send a transaction over TCP
	 * @param int $msg_id - the database id of the transaction to create
	 * @param VendorWebSocketRoute $ws_closure - the closure that handles websocket communication
	 * @return Null
	 */
	public function createMessage($msg_id, $ws_closure) {
		$app = $this;
		if (self::$debug) {
			Daemon::log(__METHOD__.' called with argument:'.$msg_id);
			//Daemon::log(__METHOD__.' callback is:'.print_r($cb, true));
		}
		
		// save our closure in appInstance so we can send data back to client 
		// over web socket
		$this->ws = $ws_closure;
		
		$this->createISOandSend($app, null, $msg_id);		
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
					if (self::$debug){
						Daemon::log('getConnection failed in '.__METHOD__);
					}
					return $job->setResult($name, 'Error connecting to MySQL Server');
				}
				
				// execute a query on our sql object
				$id_string = join(',', $ids);
				$query = "UPDATE cc_draft_log SET transmit_dt=NOW() WHERE id IN ({$id_string})";
				//Daemon::log('About to execute query:'.$query);
				//$query = "{$app->trans_query}{$msg_id}";
				$sql->query($query, function($sql, $success) use ($job, $name, $app, $query) {
					if (!$success) {
						Daemon::log('Error executing query:'.$query);
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
		$job = $req->job;
		Daemon::log('Adding job:'.$job_name);
		$req->job->addJob($job_name, function($name, $job) use ($app, $req, $q, $wake, $cb){
			// get our sql connection
			$app->sql->getConnection(function($sql, $success) use($app, $req, $q, $job, $name, $cb, $wake){
				if (!$success) {
					Daemon::log('ERROR getting SQL connection. '.__METHOD__.' error:'.$sql->errmsg);
				}
				$sql->query($q, function($sql, $success) use($app, $req, $q, $job, $name, $cb, $wake){
					// check if we should log all queries
					if ($app::$log_queries) {
						fwrite($app->qfile, $q);
					}
					if (!$success) {
						Daemon::log('ERROR executing query:'.$q.', error:'.$sql->errmsg);
						return $job->setResult($name, 'ERROR with Query! Query:'.$q.', error:'.$sql->errmsg);
					} 
					
					// create a $result variable. We set it to real values if our query succeeds
					$result = '';
					
					// evaluate whether we have an INSERT or SELECT query
					// set our $result based on the query type
					$qtype = substr($q,0,strpos($q, ' '));
					switch(trim(strtoupper($qtype))){
						case 'INSERT':
							Daemon::log(__METHOD__.' using the insertId value for job('.$name.') result!');
							//Daemon::log(__METHOD__.'$sql->insertId:'.print_r($sql->insertId, true).' q:'.$q);
							$result = $sql->insertId;
							break;
						case 'SELECT':
							Daemon::log(__METHOD__.' using the resultRows value for job('.$name.') result!');
							Daemon::log(__METHOD__.' result contains '.count($sql->resultRows). ' entries');
							//Daemon::log(__METHOD__.'$sql->resultRows:'.print_r($sql->resultRows, true));
							$result = $sql->resultRows;
							break;
						case 'UPDATE':
							Daemon::log(__METHOD__.' using the affectedRows value for job('.$name.') result!');
							//Daemon::log(__METHOD__.'$sql->affectedRows:'.print_r($sql->affectedRows, true).' q:'.$q);
							$result = $sql->affectedRows;
							break;
						default:
							Daemon::log('WARNING Unknown query type:'.$qtype);
							$result = $sql->errmsg;
					}

					// call any callback and pass it the $result from this job
					if(is_callable($cb)) {
						//$job->addListener($cb);
						//Daemon::log('About to call_user_func() and pass $result:'.print_r($result, true));
						call_user_func($cb, $result);
					}
					
					// we only set a result for this job if told to wake the request
					// this prevents the request from waking before all queries have
					// completed
					if ($wake) {
						Daemon::log('Attempting to call $req->wakeup()!');
						$job->setResult($name, $result);
						$req->wakeup();
					}
				});
			}); // end of sql->getConnection callback
			
			
			
		}); // end of job callback
		
		//  if not set to wake the request, execute our job immediately
		if (!$wake){
			Daemon::log('Executing job('.$job_name.') now!');
			$job();
		}
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
		//Daemon::log('Attempting to decrypt data with length:'.strlen($data));
		
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
			$app->vendorconn->connected = false;

			Daemon::log($failed_ip.':'.$failed_port.' disconnected. Attempting to reconnect');
			$srv_list = explode(',',$app->vendorclient->config->servers->value);
			$new_ip = array_shift($srv_list);
			if ($new_ip === $failed_ip) {
				$new_ip = array_shift($srv_list);
			}
			// recall the connect function using the other IP
			Daemon::log('About to attempt connection to: '.$new_ip.':'.$failed_port);
			$app->connect("tcp://{$new_ip}:{$failed_port}");
		});

		// add a handler for when data is sent
		$conn->addEventHandler('data_sent', function($data_length) use ($app){
			// this is really just for testing
			//Daemon::log(get_class($app). ' data_sent callback fired! Sent '.$data_length.' bytes.');
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
	
	// </editor-fold>
	
}
?>
