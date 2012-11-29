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
		
		// output a page to display in the browser
		try {$this->header('Content-Type: text/html');} catch (Exception $e) {}
		require('Vendor.index.php');
	}
}
?>
