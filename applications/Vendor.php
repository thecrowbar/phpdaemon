<?php
/**
 * Description of Vendor
 *
 * @author jcrow
 */
class Vendor extends AppInstance{
	/**
	 * database connection
	 * @var MySQLClient Object
	 */
	public $sql;
	
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
	}
	
	/**
	 * Establish a connection to the remote vendor and start a timer to send 
	 * keepalive messages
	 */
	public function connect() {
		//Daemon::log(__METHOD__.' running');
		$app = $this;
		try {
		$obj = $app->vendorclient->getConnection( function ($conn) use ($app) {
			$app->vendorconn = $conn;
			if ($conn->connected) {
				if (Daemon::$debug) {
					Daemon::log(get_class($app).' connected at '.$conn->hostReal.':'.$conn->port);
				}
				
				// add event handlers to process incoming messages and reconnect
				// automatically when the connection disconnects
				$conn->addEventHandler('data_recvd', function($msg) use ($conn, $app) {
					// this closure handles the incoming data
					// VendorClientConnection has done basic sanity checks to ensure 
					// a complete message has made it through
					if (Daemon::$debug) {
						Daemon::log('data_recvd handler fired! Processing '.strlen($msg).' bytes of data.');
					}
					
					// $msg is a string of bytes from TCP socket convert it into an object
					try {
						$iso_msg = new ISO8583();
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
						// get a MySQl connection to update the record in the DB
						$sql = $app->sql->getConnection(function($sql, $success) use ($conn, $app, $msg){
							// check for successful connection
							if (!$success) {
								Daemon::log('Failed to get MySQL connection! host:'.$sql->host);
								return;
							}
							
							// build our query to retrieve the original request data from the DB
							$fields = array('receipt_number', 'terminal_id', 'merchant_id');
							$sql_string = '';
							foreach($fields as $field) {
								if ($msg->$field !== -1) {
									if (strlen($sql_string) > 0) {
										$sql_string .= " AND {$field}='{$msg->$field}' ";
									} else {
										$sql_string = "{$field}='{$msg->$field}' ";
									}
								}
							}
							// execute a query to retrieve the original transaction
							$query = "SELECT * FROM fd_test_trans
									WHERE {$sql_string}";
							$sql->query($query, function($sql, $success) use($conn, $app, $msg, $query){
								// TODO update the client via websocket and update the database

								// get the data we need from our Database
								$sql_results = $sql->resultRows;
								if (count($sql_results) !== 1) {
									Daemon::log('Incorrect number of results found for query:'.$query);
								}
								
								// add data from the original trans into our new object
								$msg->original_trans_id = $sql_results[0]['id'];
								$msg->original_trans_amount = $sql_results[0]['trans_amount'];
								$msg->pri_acct_no = $sql_results[0]['pri_acct_no'];
								$msg->credit_card = new CreditCard($msg->pri_acct_no);
								$msg->card_type = $msg->credit_card->card_type;
								
								// at this point we have a complete transaction response
								// we need to update the original transaction in the database
								
								
								// output some basic debugging data to view
								Daemon::log($msg->getBasicDebugData());
							});
							
						});
					}
					
				});
				
				// add an event handler to reconnect when the connection ends
				$conn->addEventHandler('disconnect', function() use ($app) {
					$app->connect();
				});
				
				// add a handler for when a data is sent
				$conn->addEventHandler('data_sent', function() use ($app){
					// this is really just for testing
					Daemon::log(get_class($app). ' data_sent callback fired!');
				});
			}
			else {
				Daemon::log('VendorClient: unable to connect ('.$conn->hostReal.':'.$conn->port.')');
			}
		});
		}catch(Exception $e) {
			Daemon::log('Exception caught! $e:'.$e);
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
	 * @param int $trans_id - the database id of the transaction to create
	 * @param ComplexJob $job
	 * @return Null
	 */
	public function createMessage($msg_id) {
		$app = $this;
		if (Daemon::$debug) {
			Daemon::log(__METHOD__.' called with argument:'.$msg_id);
			//Daemon::log(__METHOD__.' callback is:'.print_r($cb, true));
		}
		
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
				$sql->query("{$app->trans_query}{$msg_id}", function($sql, $success) use ($job, $name, $app) {
					if (!$success) {
						Daemon::log('Error executing query:'.$app->trans_query.$msg_id);
						return $job->setResult($name, 'Error with Query!');
					}
					
					// create a vendor message from the SQL result
					$db_row = $sql->resultRows;
					if (is_array($db_row)) {
						try {
							// TODO change this for your own needs
							$app->vendorMessage = new VendorMessage($db_row);

							// send the data to the remote vendor
							$app->vendorclient->getConnection(function($conn) use ($app){
								$conn->sendData($app->vendorMessage->getTCPMessage());
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
}
?>