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

		// create a route for websocket use Default WebSocket port is 8047.
		// The path is 'VendorWS'. Change it below if required
		$appInstance = $this; // a reference to this application instance for the WebSocketRoute
		// URI /exampleApp should be handled by ExampleWebSocketRoute
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

class VendorWebSocketRoute extends WebSocketRoute { 
	/**
	 * AppInstance of the parent of this object
	 * @var Vendor Object
	 */
	public $appInstance;

	/**
	 * Called when new frame received.
	 * @param string Frame's contents.
	 * @param integer Frame's type.
	 * @return void
	 */
	public function onFrame($data, $type) {
		if (Daemon::$debug) {
			Daemon::log('data:'.$data.' received from websocket client');
		}
		
		// attmept to JSON decode the frame. Try to recover the object
		$client_data = json_decode($data);
		if (is_object($client_data)) {
			// we have a good decode
			if (Daemon::$debug) {
				Daemon::log('Decoded a JSON object! Object:'.print_r($client_data, true));
			}
			// act on our object
			if ($client_data->command === 'send_trans') {
				$this->appInstance->createMessage($client_data->trans_id);
			} else {
				// unknown command
				$this->client->sendFrame('Unknown command:'.$client_data->command, 'STRING');
			}
		} else {
			$this->client->sendFrame('Error '.json_last_error().', trying to decode data', 'STRING');
			if (Daemon::$debug) {
				Daemon::log('Received object:'.print_r($client_data, true));
			}		
		}
	}
}

/**
 * VendorRequest extends HTTPRequest and handles all inbound requests from a web browser
 */
class VendorRequest extends HTTPRequest{
	/**
	 * Any jobs this object will run
	 * @var ComplexJob Object
	 */
	public $job;
	/**
	 * ID of the transaction in the DB that this request will handle
	 * @var int
	 */
	protected $trans_id = -1;
	
	/*
	 * A reference back to itself for use in call backs
	 * @var FirstDataRequest Object
	 */
	protected $req;

	public $iso_exception = null;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->req = $this;
		$req = $this;
		
		// create a job to query the database for pending transactions 
		$job = $this->job = new ComplexJob(function() use ($req){
			// wake the request up imediately when the job finishes
			$req->wakeup();
		});
		
		$job->addJob('pending_trans', function($name, $job) use ($req){
			
			// we get a connection to our SQL server here. I saved the connection
			// object in a variable for testing, but it is not necessary to do so
			$tsql = $req->appInstance->sql->getConnection(function($sql, $success) use ($name, $job, $req) {
				// the callback receives the MySQLClientConnection object and a
				// boolean success flag
				if (!$success) {
					if (Daemon::$debug){
						Daemon::log('getConnection failed in ');
					}
					return $job->setResult($name, 'Error connecting to MySQL Server');
				}
				
				$query = "SELECT * FROM {$req->appInstance->config->sqltable->value}";
				$sql->query($query, function($sql, $success) use ($job, $name) {
					if (!$success) {
						if (Daemon::$debug){
							Daemon::log('$sql->query() failed with error:'.$sql->errmsg);
						}
						return $job->setResult($name, 'Error with Query!');
					}
					
					// save our results in the job
					$job->setResult($name, $sql->resultRows);
				});
			}); // end of getConnection()
			
//			// this was for my testing of the MySQL connection			
//			if (Daemon::$debug) {
//				//Daemon::log(Debug::dump($tsql));
//			}
		});
		

		// run our job
		$job();
		
		if (Daemon::$debug) {
			Daemon::log(__METHOD__.' being put to sleep for 5 seconds');
		}
		
		// sleep for 5 seconds to give the query time to execute
		// if the sleep() method is called outside of the run() method then the
		// second parameter must be true
		$this->sleep(5, true);
		
	}
	
	/**
	 * 
	 */
	public function run() {
		$req = $this;
		
		if (Daemon::$debug) {
			Daemon::log(__METHOD__.' running');
			
		}
		
		
		// This is done by websocket communication now. It is left here as an example
//		// grab any trans_id from the $req object
//		if (array_key_exists('trans_id',$req->attrs->get) && is_numeric($req->attrs->get['trans_id'])) {
//			$this->trans_id = $req->attrs->get['trans_id'];
//			if (Daemon::$debug) {
//				Daemon::log('found trans_id in the request! trans_id:'.$this->trans_id);
//			}
//			
//			// create our transaction and send it off
//			$this->appInstance->createTransaction($this->trans_id);
//			
//		}
		
		
		
		// output a page to display in the browser
		
		try {$this->header('Content-Type: text/html');} catch (Exception $e) {}
?>
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
<script type="text/javascript" src="../js/Vendor.js"></script>
<title>Billing System</title>
</head>
<body>
	<div id="content">
		<h2>Billing System</h2>
		<?php
		if (is_array($this->job->getResult('pending_trans'))) {
			$trans = $this->job->getResult('pending_trans');
			// we have pending transactions to display
			echo "<table class='border1' id='pending_trans'>
				<thead>
					<tr>
						<th>ID</th>
						<th>Test #</td>
						<th>Msg Type</th>
						<th>Account #</th>
						<th>Amount</th>
						<th>Auth Iden Resp</th>
						<th>Response Code</th>
					</tr>
				</thead>
				<tbody>\n";
			echo "There are ".count($this->job->getResult('pending_trans'))." transactions to display<br />\n";
			$row_count = 0;
			for ($i =0; $i < count($trans); $i++) {
				$add_class = ' odd ';
				if ($row_count % 2) {
					$add_class = ' even ';
				}
				echo "<tr class='pending_trans {$add_class}' trans_id='{$trans[$i]['id']}'>
						<td>{$trans[$i]['id']}</td>
						<td>{$trans[$i]['test_num']}</td>
						<td>{$trans[$i]['msg_type']}</td>
						<td>{$trans[$i]['pri_acct_no']}</td>
						<td>{$trans[$i]['trans_amount']}</td>
						<td>{$trans[$i]['auth_iden_response']}</td>
						<td>{$trans[$i]['response_code']}</td>
					</tr>\n";
				$row_count++;
			}
			echo "
				</tbody>
			</table><br />\n";
		}
		?>
	</div>
	<h4>This section just for testing. Remove for production code.</h4>
	<button onclick="create();">Create WebSocket</button>
	<button onclick="ws.send('ping');">Send ping</button>
	<button onclick="ws.close();">Close WebSocket</button>
	<div id="log" style="width:600px; height: 100px; border: 1px solid #999999; overflow:auto;"></div><br />
	<button onclick="ws.send('command');">Send Command</button><button onclick="sendObject({command:'send_trans',trans_id:'1234'});">Send Object</button><br />
	<button onclick="sendText();">Send Text</button><input type="text" name="command" id="command" />
</body>
</html>
<?php
	}
}
?>
