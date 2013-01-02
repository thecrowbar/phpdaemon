<?php
/**
 * Description of Vendor
 *
 * @author jcrow
 */
class Vendor extends AppInstance{
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
	 * Query to run to retrieve a transaction from the database
	 * @var String
	 */
	public $trans_query;
	
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
	 * First method called when a new object in created.
	 */
	public function init() {
		// start xdebug trace of our code
		xdebug_start_trace('/var/log/phpdaemon/Vendor.trace');
		
		// give ourself plenty of RAM
		ini_set('memory_limit', '900M');
		
		// set our config values for easier access
		$this->vendorhost = $this->config->vendorhosts->value;
		$this->vendorport = $this->config->vendorport->value;
		$this->trans_query = $this->config->trans_query->value;
		
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
						'server'=>"mysql://{$sql_user}:{$sql_pass}@{$sql_host}/{$sql_db}"
				)
		);
		$this->sql = MySQLClient::getInstance($mysql_config);
//		Daemon::log('$this->sql:'.print_r($this->sql, true));
//		exit();
		
		// create a connection to the MySQl server for use later
		$this->sql_conn = $this->sql->getConnection();
		
		// initialize our queue
		$this->tq = new SplQueue();

	}
	

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		// if we have a client object, make sure it is ready and connected
		if ($this->vendorclient) {
			$this->vendorclient->onReady();
			$this->connect();
		}

		// create a route for websocket use 
		// Default WebSocket port is 8047.
		// The path is 'VendorWS'. Change it below if required
		$appInstance = $this; // a reference to this application instance for the WebSocketRoute
		WebSocketServer::getInstance()->addRoute('VendorWS', function ($client) use ($appInstance) {
			return new VendorWebSocketRoute($client, $appInstance);
		});
		
		// start a timer to check the outbound queue every 90 seconds
		$app = $this;
		$this->keepaliveTimer = setTimeout(function($timer) use($app) {
			Daemon::log(__METHOD__.' timer callback firing');
			$app->checkOutboundQueue($app);
		}, 1e6 * 90);
	}
	
	/**
	 * Establish a connection to the remote vendor and start a timer to send 
	 * keepalive messages
	 */
	public function connect() {
		//Daemon::log(__METHOD__.' running');
		$app = $this;
		$url = '';
		// check for a passed callback and execute it when the job completes
		$args = func_get_args();
		$callbacks = array();
		if (count($args) > 0) {
//			if (Daemon::$debug) {
//				Daemon::log('connect() args:'.print_r($args, true));
//			}

			// check the first arguments to see if it a url
			if (!is_object($args[0]) && !is_callable($args[0])) {
				$url = array_shift($args);
			}
			// make sure the argument is callable
			foreach($args as $arg) {
				if (is_object($arg) && is_callable($arg)) {
					$callbacks[] = $arg;
					if (Daemon::$debug) {
						Daemon::log(__METHOD__.' callback added!');
					}
				} else {
					Daemon::log($arg.' is not an object and not callable');
				}
			}
		}
		if (Daemon::$debug) {
			Daemon::log('Found '.count($callbacks).' callbacks for '.__METHOD__);
		}
		
		// check our connection, if it is connected then we run callbacks and return
		if (!is_object($app)) {
			Daemon::log('$app is not an object. FATAL! $this:'.print_r($this, true));
		}
		if (is_object($app->vendorconn) && $app->vendorconn->connected) {
			if (count($callbacks) > 0) {
				foreach($callbacks as $cb) {
					call_user_func($cb);
				}
			}
			//return true;
		} else {
//			if (Daemon::$debug) {
//				Daemon::log('Vendor::connect() using VendorClient config:'.print_r($app->vendorclient->config, true));
//			}
			// if we have a url use it instead of the an auto selected default
			if (strlen($url) > 0) {
//				if (Daemon::$debug) {
//					Daemon::log('Vendor::connect() using VendorClient config:'.print_r($app->vendorclient->config, true));
//				}
				$app->vendorclient->getConnection( $url, function ($conn, $success) use ($app, $callbacks) {
					if (!$success) {
						Daemon::log('getConnection() failed!');
					}
					$app->vendorconn = $conn;
					if ($conn->connected) {
						if (Daemon::$debug) {
							Daemon::log(get_class($app).' connected at '.$conn->hostReal.':'.$conn->port);
						}

						Vendor::attachEventHandlers($conn, $app, $msg);

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
				// use an auto selected value from the servers list
				$obj = $app->vendorclient->getConnection( function ($conn) use ($app, $callbacks) {
					$app->vendorconn = $conn;
					if ($conn->connected) {
						if (Daemon::$debug) {
							Daemon::log(get_class($app).' connected at '.$conn->hostReal.':'.$conn->port);
						}

						Vendor::attachEventHandlers($conn, $app);

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
				if (is_object($obj) && !$obj->connected) {
					// connection failed. try other IP
					$srv_list = explode(',',$app->vendorclient->config->servers->value);
					Daemon::log('Servers list:'.print_r($srv_list, true));
				}
			}
		}
	}
	
	/**
	 * processReceivedData() - process the data returned by the remote vendor
	 * @param byte[] $msg - the data received from the vendor
	 * @param VencodrClientConnection $conn - the remote connection
	 * @param Vendor $app - a link back to this object
	 */
	public function processReceivedData($msg, $conn, $app){
		// this closure handles the incoming data
		// VendorClientConnection has done basic sanity checks to ensure 
		// a complete message has made it through
		if (Daemon::$debug) {
			Daemon::log('data_recvd handler fired! Processing '.strlen($msg).' bytes of data.');
		}

		// $msg is a string of bytes from TCP socket convert it into an object
		try {
			$iso_msg = new ISO8583Trans();
			$iso_msg->addTCPMessage($msg);
			$msg = $iso_msg;
		} catch(Exception $e){
			Daemon::log('Exeception caught trying to recreate ISO8583 from TCP data! Exception:'.$e);
		}
		if ($msg->msg_type === ISO8583::MSG_TYPE_AUTH_RESP 
				|| $msg->msg_type === ISO8583::MSG_TYPE_REV_RESP) {
			// this is a repsone to one of our messages, we need some information from the database
			if (Daemon::$debug) {
				Daemon::log('Processing an AUTH_RESP or REV_RESP message');
			}
			
			// log what our $app is
			//Daemon::log('$app is of type:'.get_class($app));
			//exit();
//			Daemon::log('$app->sql_conn:'.print_r($app->sql_conn, true));
//			Daemon::log('$app->sql:'.print_r($app->sql, true));
			//exit();
			// get a MySQl connection to update the record in the DB
			$sql_conn = $app->sql->getConnection(function($sql) use ($conn, $app, $msg){
				// check for successful connection
				// if this callback runs we got a connection!
//				if (!$success) {
//					Daemon::log('Failed to get MySQL connection! host:'.$sql->host);
//					return;
//				}else {
//					Daemon::log('Got MySQL Connection!');
//				}
				
				Daemon::log(__FILE__.':'.__METHOD__.':'.__LINE__.' executing 2');

				$query = Vendor::buildQueryForOriginalTrans($msg, $app);
				if (Daemon::$debug) {
					Daemon::log('About to execute query:'.$query);
				}
				$sql->query($query, function($sql, $success) use($conn, $app, $msg, $query){
					Daemon::log(__FILE__.':'.__METHOD__.':'.__LINE__.' executing 3');
					// check for a successful sql query
					if (!$success) {
						Deamon::log('Failed to run query:'.$query.' SQL Error:'.$sql->errmsg);
						return;
					}else{
						Daemon::log(__FILE__.':'.__METHOD__.':'.__LINE__.' executing');
					}
					// get the data we need from our Database
					$sql_results = $sql->resultRows;
					if (count($sql_results) !== 1) {
						//Daemon::log('Incorrect number of results found for query:'.$query);
						Daemon::log('Incorrect number of results found for query:'.$query);
					}

					// add data from the original trans into our new object
					try{
						if (Daemon::$debug) {
							Daemon::log('Original trans id:'.$sql_results[0]['id']);	
						}
						$msg->original_trans_id = $sql_results[0]['id'];
						$msg->original_trans_amount = $sql_results[0]['trans_amount'];
						if (Vendor::$decrypt_data) {
							$data = Vendor::decrypt_data($sql_results[0]['pri_acct_no']);
							if (is_array($data)){
								throw new Exception('Error decrypting account data! Error:'.$data['error_msg']);
							}else {
								$sql_results[0]['pri_acct_no'] = $data;
							}
						}
						$msg->pri_acct_no = $sql_results[0]['pri_acct_no'];
						$msg->credit_card = new CreditCard($msg->pri_acct_no);
						$msg->card_type = $msg->credit_card->card_type;
						if (Daemon::$debug) {
							Daemon::log('Finished adding data from original trans!');
						}
					}catch(Exception $e){
						Daemon::log('Exception caught trying to update transaction with original trans data! Exception:'. $e);
					}

					// at this point we have a complete transaction response
					// we need to update the original transaction in the database
					$app->updateTransInDB($msg, $app);
					

					// update the client over websocket that we received a response
					if (is_object($app->ws)) {
						$app->updateClientWS($app->ws, $msg);
					} else {
						if (Daemon::$debug){
							//Daemon::log(Debug::dump($app));
						}
						Daemon::log('$app->ws is not an object! Unable to send response over websocket!');
					}


					// output some basic debugging data to view
					Daemon::log($msg->getBasicDebugData());
					if (method_exists($msg, 'getBit63DebugData')){
						//Daemon::log($msg->getBit63DebugData());
					}
				});

			});
			// check if our SQL connection failed
//			if (Daemon::$debug) {
//				if (is_object($sql_conn) && $sql_conn->connected){
//					Daemon::log('$sql_conn->connected == true');
//				}else {
//					Daemon::log('$sql_conn->connected !== true');
//					Daemon::log(print_r($sql_conn, true));
//					//exit();
//				}
//				
//			}
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
			Daemon::log('Exception caught trying to create object for websocket! Exception:'.$e);
		}
	}
	
	/**
	 * updateTransInDB() - save the response from the remote vendor for this message
	 * @param ISO8583Trans $msg - the message returned from the remote vendor
	 */
	public function updateTransInDB($msg, $app) {
		//FIXME
		//TODO Update this code
		$app->sql->getConnection(function($sql, $success) use ($app, $msg){
			// check for successful connection
			if (!$success) {
				Daemon::log('Failed to get MySQL connection! host:'.$sql->host);
				return;
			}else {
				Daemon::log('Got MySQL Connection!');
			}
			
			// there are several queries required to update the different tables
			$queries = Vendor::buildQueryForTransUpdate($msg, $app);
			$query = array_shift($queries);
				
			$sql->query($query, function($sql, $success) use($app, $msg, $query, $queries){
				if ($success) {
					//Daemon::log(Debug::dump($sql));
					//Daemon::log('Successfully updated transaction with id '.$msg->original_trans_id.' query:'.$query);
					Daemon::log('Successfully updated transaction with id '.$msg->original_trans_id);
				} else {
					//Daemon::log(Debug::dump($sql));
					Daemon::log('Error updating DB! query:'.$query.', error:'.$sql->errmsg);
				}
				
				// check if we have any queries to run
				if (count($queries) > 0) {
					// run the next query
					$query = array_shift($queries);
					$sql->query($query, function($sql, $success) use($msg, $query, $queries){
						if ($success) {
							//Daemon::log(Debug::dump($sql));
							//Daemon::log('Successfully updated transaction with id '.$msg->original_trans_id.' query:'.$query);
							Daemon::log('Successfully updated transaction with id '.$msg->original_trans_id);
						} else {
							//Daemon::log(Debug::dump($sql));
							Daemon::log('Error updating DB! query:'.$query.', error:'.$sql->errmsg);
						}
						
						if (count($queries) > 0) {
							// run the next query
							$query = array_shift($queries);
							$sql->query($query, function($sql, $success) use($msg, $query, $queries){
								if ($success) {
									//Daemon::log(Debug::dump($sql));
									//Daemon::log('Successfully updated transaction with id '.$msg->original_trans_id.' query:'.$query);
									Daemon::log('Successfully updated transaction with id '.$msg->original_trans_id);
								} else {
									//Daemon::log(Debug::dump($sql));
									Daemon::log('Error updating DB! query:'.$query.', error:'.$sql->errmsg);
								}
								
								if (count($queries) > 0) {
									Daemon::log('There are '.count($queries).' left to run, but recursion limit reached!');
									Daemon::log('Queries:'.print_r($queries,true));
								}
							});
						}
					});
				}
			});
		});
	}
	
	/**
	 * checkOutboundQueue() - check outbound queue for message and send
	 * @param Vendor $app
	 */
	public function checkOutboundQueue($app){
		if (Daemon::$debug) {
			Daemon::log(__METHOD__.' running!');
		}
		// if there any items in the queue then send one off 
		if (count($app->tq) > 0) {
			// item exists in queue; check connection
			if (Daemon::$debug) {
				Daemon::log(__METHOD__.' '.count($app->tq).' items exist in queue. Attempting to send one!');
				Daemon::log('About to start while() trying to get connection');
			}
			
			while (!$app->vendorconn->connected) {
				$app->connect();
				if (Daemon::$debug) {
					Daemon::log('Sleeping 10 seconds to wait for connection to establish');
				}
				//sleep(10);
			}
			if (Daemon::$debug) {
				Daemon::log('while() loop completed! Connected state:'.$app->vendorconn->connected);
			}
			// check connection state and if established send message
			if ($app->vendorconn->connected) {
				Daemon::log('Attempting to send data to '.$app->vendorconn->hostReal);
				$msg = $app->tq->dequeue();
				$app->vendorconn->sendData($msg->getTCPMessage());
			}
				
			//$conn = $app->get
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
		if (Daemon::$debug) {
			Daemon::log(__METHOD__.' called with argument:'.$msg_id);
			//Daemon::log(__METHOD__.' callback is:'.print_r($cb, true));
		}
		
		// save our closure in appInstance so we can send data back to client 
		// over web socket
		$this->ws = $ws_closure;
		
		// create a job to store our MySQL results
		$job = $this->job = new ComplexJob();
		
		// register the trans_row job. This is where the main data to create ISO8583
		// will come from
		$job->addJob('msg_row', function($name, $job) use($app, $msg_id){
			// the addJob callback gets called with the job name and job object as callbacks
			// we also need access to our appInstance and the msg_id for our query
			
			// get a connection to our MySQL database
			$app->sql->getConnection(function($sql, $success) use ($name, $job, $app, $msg_id) {
				// the MySQL getConnection callback is passed the MySQL object and a boolean
				// success flag
				
				if (!$success) {
					if (Daemon::$debug){
						Daemon::log('getConnection failed in '.__METHOD__);
					}
					return $job->setResult($name, 'Error connecting to MySQL Server');
				}
				
				// execute a query on our sql object
				$query = "{$app->trans_query}{$msg_id}";
				$sql->query($query, function($sql, $success) use ($job, $name, $app, $query) {
					if (!$success) {
						Daemon::log('Error executing query:'.$app->trans_query.$msg_id);
						return $job->setResult($name, 'Error with Query! Query:'.$query);
					}
					
					// create a vendor message from the SQL result
					$db_row = $sql->resultRows;
					if (is_array($db_row)) {
						try {
							// TODO change this for your own needs
							$app->vendorMessage = new VendorMessage($db_row);
							
							// add the message to our queue
							$app->tq->enqueue($app->vendorMessage);

							// send the data to the remote vendor
							Daemon::log('About to call Vendor->connect and pass in callback to checkOutboundQueue');
							$app->connect(function() use ($app){
								$app->checkOutboundQueue($app);
							});
							$job->setResult($name, 'Sent message data');
						}catch(Exception $ex) {
							Daemon::log('Caught exception in '.__METHOD__.':'.$ex);
							$job->setResult($name, 'Data send failed!');
						}
							
					} else {
						if (Daemon::$debug) {
							Daemon::log('job returned no result! '.print_r($db_row, true));
						}
						$job->setResult($name, 'Unable to create data from database row!');
					}
					
				});
			
			});
		});
		
		// run our job to actually connect to MySQL and execute the query
		$job();
		
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
					if (Daemon::$debug){
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
						return $job->setResult($name, 'Error with Query! Query:'.$query);
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
	
	
	// <editor-fold defaultstate="collapsed" desc="Static Methods">
	/**
	 * decrypt_data() - decrypt an encrypted string that is also base64 encoded 
	 * @param String $data - encrypted data
	 * @return string - plain text data
	 */
	public static function decrypt_data($data)
	{
		if (!defined('PRIVATE_KEY')) {
			define('PRIVATE_KEY','file:///opt/phpdaemon/rsa-1024-priv.key');
		}
		$error = array();
		$error['error_msg'] = '';
		//Daemon::log('Attempting to decrypt data with length:'.strlen($data));
		
		$priv_key = openssl_get_privatekey(PRIVATE_KEY);
		if (!$priv_key)
		{
			// error opening private key
			$error['error_msg'] = 'Unable to open private key ('.PRIVATE_KEY.')!';
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
			$pos = strpos($decrypted, '::::');
			//echo "\$pos: $pos\n";
			$pdata = substr($decrypted, 0, $pos );
			//Daemon::log('Decryption success! Plain text data length:'.strlen($pdata));
			if (Vendor::$test_set_only) {
				// check that this card number is one of the test cards and stop if it is not
				// this is to ensure we do not send live cards to the test environment
				if (in_array($pdata, Vendor::$TEST_SET_NUMS)) {
					return $pdata;
				} else {
					$error['error_msg'] = 'Data is not in the test set and $test_set_only is set!';
					return $error;
				}
			} else {
				return $pdata;
			}
		}
		else
		{
			//error_log('Error decrypting! $eccnum:'.$eccnum);
			$error['error_msg'] = 'Error decrypting $eccnum:'.$data;
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
			Daemon::log(get_class($app). ' data_sent callback fired! Sent '.$data_length.' bytes.');
		});
	}
	

	/**
	 * Build the query to retrieve the transaction details of the original transaction
	 * @param ISO8583Trans $msg - the ISO8583 message returned from remote vendor
	 * @return string
	 */
	public static function buildQueryForOriginalTrans($msg, $app) {
		// build our query to retrieve the original request data from the DB
		$query = "SELECT fdd.*, fmi.tid AS terminal_id, 
				fmi.mid AS mercant_id
			FROM fd_draft_data fdd
			LEFT JOIN fd_merchant_info fmi ON fmi.id = fdd.merchant_id
			WHERE receipt_number = '{$msg->receipt_number}' AND tid = '{$msg->terminal_id}'";
		//Daemon::log('Query for original trans:'.$query);
		return $query;
	}
	
	/**
	 * buildQueryForTransUpdate() - Build the queries to update the transaction in the
	 * database when a response is received
	 * @param ISO8583Trans $msg - our object returned from the remote vendor
	 * @param Vendor $app - our Vendor object (extends appInstance)
	 */
	public static function buildQueryForTransUpdate($msg, $app) {
		//daemon::log('$msg:'.print_r($msg, true));
		$queries = array();
		// build the main table update query
		// create our update query
		$auth_iden_sql = '';
		if ($msg->dataExistsForBit(38)) {
			$auth_iden_sql = " auth_iden_response='{$msg->getDataForBit(38)}' ";
		}
		$resp_code_sql = '';
		if ($msg->dataExistsForBit(39)) {
			$resp_code_sql = " response_code = '{$msg->getDataForBit(39)}' ";
		}
		$avs_resp_sql = '';
		if ($msg->dataExistsForBit(44)){
			$avs_resp_sql = " avs_response = '{$msg->getDataForBit(44)}' ";
		}
		$resp_text_sql = '';
		if ($msg->dataExistsForTable(22)) {
			$resp_text_sql = " response_text = '{$msg->getParsedBit63Table22()}' ";
		}
		$sql_strings = array($auth_iden_sql, $resp_code_sql, $avs_resp_sql, $resp_text_sql);
		
		
		// form an actual sql string from our data
		$sql_snippet = '';
		for($i = 0; $i < count($sql_strings); $i++) {
			if (strlen($sql_snippet) == 0) {
				// no SQL in snippet yet
				$sql_snippet = $sql_strings[$i];
			} else {
				$sql_snippet .= ','.$sql_strings[$i];
			}
		}

		$query = "UPDATE {$app->config->sqltable->value} SET {$sql_snippet}
			WHERE id = {$msg->original_trans_id}";
		$queries[] = $query;
		
		// <editor-fold defaultstate="collapsed" desc="Table14 Card Specific Queries">
		// now create query specific to card type based on table14
		switch($msg->card_type) {
			case 'Visa' :
				if ($msg->dataExistsForTable(14)) {
					$tbl14 = $msg->getParsedBit63Table14();
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
				if ($msg->dataExistsForTable(14)) {
					$tbl14 = $msg->getParsedBit63Table14();
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
				Daemon::log('TODO Write query to update card type:'.$msg->card_type);
		}
		//</editor-fold>
		
		//<editor-fold defaultstate="collapsed" desc="Card Compliance/Qualification Tables">
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
		//</editor-fold>
		return $queries;
	}
	
	/**
	 * getSubmitDraftSQL() - return the SQL query used to retrieve the batch
	 * transactions to submit
	 * @var String (YYYY-MM-DD) - the draft date to pull transactions for
	 * @return String
	 */
	public static function buildSubmitDraftSQL($draft_date) {
		$query = "SELECT fdd.* 
							FROM fd_draft_data fdd
							LEFT JOIN cc_draft_log cdl ON cdl.id = fdd.batch_id
							WHERE fdd.schedule_date = '{$draft_date}' 
								AND response_code = ''
								AND cdl.approve_user <> ''
								AND cdl.approve_dt <> '0000-00-00 00:00:00'
								AND cdl.approve_ip_address <> ''";
		return $query;
	}
	
	/**
	 * get_type() - Return the type of the passed $var. Useful for debugging.
	 * @param Any $var
	 * @return string
	 */
	public static function get_type($var) 
	{ 
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
