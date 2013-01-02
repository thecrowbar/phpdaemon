<?php
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
	 * @var VendorRequest Object
	 */
	protected $req;

	public $iso_exception = null;
	/**
	 * This is the HTML file to include for output
	 * @var String
	 */
	public $html_file = 'Vendor.index.php';
	/**
	 * The error message to display
	 * @var String
	 */
	public $err_msg = '';
	/**
	 * The draft date to process
	 * @var String (Date) YYYY-MM-DD
	 */
	public $draft_date = '';
	/**
	 * The parent appInstance
	 * @var Vendor 
	 */
	public $app;
	
	public function __construct($url = null, $request_method = null, $options = nullarray) {
		if (is_object($url)) {
			Daemon::log('We got passed an object as our $url!');
			Daemon::log('$url is of type:'.Vendor::get_type($url));
			$this->app = $url;
		}
		parent::__construct($url, $request_method, $options);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->req = $this;
		$req = $this;
		
		// This determines which job we execute based on data passed from client
		$which_job = 'pending_trans';
		
		// determine what we should do
		if (isset($options)) {
			Daemon::log('$options is set and is type:'.Vendor::get_type($options));
			Daemon::log('$options:'.print_r($options, true));
		}
		
		Daemon::log('$this->app is of type:'.Vendor::get_type($this->app));
		$options = array();
		// our request has not yet been processed. We must decode the wuery string ourselves
		if (strlen($this->attrs->server['QUERY_STRING']) > 0) {
			// we have a query to decode
			Daemon::log('Attempting to decode:'.$this->attrs->server['QUERY_STRING']);
			parse_str($this->attrs->server['QUERY_STRING'], $options);
		}
		
		if (Daemon::$debug) {
			Daemon::log(__METHOD__.' running');
			//Daemon::log('$req:'.print_r($req, true));
		}
		
		// create a job to query the database for pending transactions 
		$job = $this->job = new ComplexJob(function() use ($req){
			// wake the request up imediately when the job finishes
			$req->wakeup();
		});
		
		if (array_key_exists('stdin', $options)) {
			$req->err_msg = "Attempting to use saved buffer for processing";
			$req->html_file = 'Vendor.stdin_log.php';
			// create a job to read in our buffer
			$job->addJob('read_buffer', function($name, $job) use ($req){
				//$result = file_get_contents('/opt/phpdaemon/stdin_raw2.log');
				$result = file_get_contents('/opt/phpdaemon/stdin_raw3.log');
				//$result = file_get_contents('/opt/phpdaemon/xad');
				return $job->setResult($name, $result);
			});
		} else if (array_key_exists('command', $options) && $options['command'] === 'submit_draft') {
			// here we submit a draft
			// we must have a draft_date as well
			$error = false;
			if (!array_key_exists('draft_date', $options)) {
				$req->html_file = 'Vendor.error.php';
				$req->err_msg = 'Missing draft date value! Unable to process without a date!';
			} else if (date('Y-m-d', strtotime($options['draft_date'])) === '1969-12-31') {
				$req->html_file = 'Vendor.error.php';
				$req->err_msg = 'Invalid date given. Must be YYYY-MM-DD format.';
			}
			
			if ($error === false) {
				$which_job = 'submit_draft';
				// set our draft date
				$req->draft_date = date('Y-m-d', strtotime($options['draft_date']));
				
				// check if we should process or just display
				if (array_key_exists('process', $options) && $options['process'] === 'true') {
					// process the draft
					$req->html_file = 'Vendor.process.php';
				} else {
					// display the draft
					$req->html_file = 'Vendor.display_draft.php';
				}
			}
		} else {
			Daemon::log('NOT SUBMITTING DRAFT! $options:'.print_r($options, true));
		}
		
		if (strlen($this->err_msg) < 1) {
			if ($which_job === 'submit_draft') {
				// draft job
				$job->addJob('submit_draft', function($name, $job) use ($req){
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
						$query = Vendor::buildSubmitDraftSQL($req->draft_date);
						Daemon::log('Query to get draft transactions:'.$query);
						$sql->query($query, function($sql, $success) use ($job, $name, $query) {
							if (!$success) {
								if (Daemon::$debug){
									Daemon::log('$sql->query() failed with error:'.$sql->errmsg);
								}
								return $job->setResult($name, 'Error with Query! Query:'.$query.', error:'.$sql->errmsg);
							}

							// save our results in the job
							$job->setResult($name, $sql->resultRows);
						});
					}); // end of getConnection()
				});
				
				// add a job to get the list of batch_ids that will be processed
			} else {

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

						$query = $req->appInstance->config->pending_batch_query->value;
						$sql->query($query, function($sql, $success) use ($job, $name, $query) {
							if (!$success) {
								if (Daemon::$debug){
									Daemon::log('$sql->query() failed with error:'.$sql->errmsg);
								}
								return $job->setResult($name, 'Error with Query! Query:'.$query.', error:'.$sql->errmsg);
							}

							// save our results in the job
							$job->setResult($name, $sql->resultRows);
						});
					}); // end of getConnection()

				});
			}
		} else {
			// create no job because we will display the error
		}
		

		// run our job
		$job();
		
		$sleep_time = 2;
		if (Daemon::$debug) {
			Daemon::log(__METHOD__.' being put to sleep for '.$sleep_time.' seconds');
		}
		
		// sleep for 5 seconds to give the query time to execute
		// if the sleep() method is called outside of the run() method then the
		// second parameter must be true
		$this->sleep($sleep_time, true);
		
	}
	
	/**
	 * 
	 */
	public function run() {
		$req = $this;
		
		// this is mainly just for my testing
		// output a page to display in the browser
		try {$this->header('Content-Type: text/html');} catch (Exception $e) {}
		require($req->html_file);
		
			
	}
}
?>
