<?php
/**
 * VendorRequest extends HTTPRequest and handles all inbound requests from a web browser
 *
 * @author James Crow (jcrow@daemon.io)
 */
class RealTimeTransRequest extends HTTPRequest{
	/**
	 * Related Application instance
	 * @override Request->appInstance
	 * @var RealTimeTrans
	 */
	public $appInstance;
	/**
	 * Any jobs this object will run
	 * @var ComplexJob Object
	 */
	public $job;
	/**
	 * ID of the transaction in the DB that this request will handle
	 * @var int
	 */
	protected $transID = -1;
	
	/**
	 * A reference back to itself for use in call backs
	 * @var RealTimeTransRequest Object
	 */
	protected $req;

	/**
	 * Assoc array of the values from the HTTP request, get, and post
	 * @var Array()
	 */
	protected $req_params = array();
	
	/**
	 * What this class should do. Sent in by HTTP
	 * @var String
	 */
	protected $cmd = '';
	
	/**
	 * The ISO8583 transaction object we will pass to FD
	 * @var ISO8583Trans
	 */
	protected $iso;
	
	public $iso_exception = null;
	/**
	 * This is the HTML file to include for output
	 * @var String
	 */
	//public $html_file = 'RealTimeTrans.index.php';
	public $html_file = 'RealTimeTrans.debug.php';
	
	/**
	 * Script that outputs the HTML frontend for the view transaction page
	 * @var String
	 */
	public $trans_view_html = 'RealTimeTrans.index.php';
	
	/**
	 * Indicates if there is any error
	 * @var Boolean
	 */
	public $error = 0;
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
	 * The name=>value pairs from the URL
	 * @var Array[]
	 */
	public $options;
	
	/**
	 * Array of receipt numbers that still need responses **TESTING ONLY**
	 * @var int[]
	 */
	public $receipt_nums = array();
	
	/**
	 * The raw track2 data from the CC. This cannot be saved anywhere. It exists only in memory
	 * @var String
	 */
	private $track2 = '';
	
	/**
	 * Flag to indicate if we are only viewing current transactions
	 * @var bool
	 */
	public $view_trans = false;
	
	/**
	 * Flag to indicate if we should view the transaction details for a single transaction
	 * @var bool
	 */
	public $view_trans_detail = false;
	
	/**
	 * File that outputs HTML page of transaction detail
	 * @var String
	 */
	public $trans_detail_html = 'RealTimeTrans.detail.php';
	
	/**
	 * Should the transaction detail output only the div or a complete HTML page
	 * @var bool
	 */
	public $trans_detail_div_only = false;
	
	public $help_file_html = 'RealTimeTrans.help.php';
	
	/**
	 * Defined constant from VendorRequest. It tells the view what data to look for
	 * @var Int
	 */
	public $process_mode;
	
	/**
	 * The $app->createJobFromQuery() method will automatically rename a job
	 * if it fails. This is where it will store the last job_nam.
	 * @var String
	 */
	public $last_job_name = '';
	
	/**
	 * The amount of time this request should sleep before waking itself up.
	 * 180 seconds gives plenty of time for auto reversal timers to fire
	 * @var Int
	 */
	public $sleep_time = 180;
	
//	public function __construct($url = null, $request_method = null, $options = nullarray) {
//		if (is_object($url)) {
//			Vendor::log(Vendor::LOG_LEVEL_DEBUG, 'We got passed an object as our $url!');
//			Vendor::log(Vendor::LOG_LEVEL_DEBUG, '$url is of type:'.Vendor::get_type($url));
//			$this->app = $url;
//		}
//		parent::__construct($url, $request_method, $options);
//	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		// save a copy of our object for passing to callbacks
		$req = $this;
		$app = $req->appInstance;

		// disable caching
		$this->disableCaching();
		// import our values from the HTTP request
		$this->req_params = RealTimeTransRequest::importRequestValues($this->attrs);
		// validate our request
		$this->valuesPresentInRequest();
		if ($this->error === 1) {
			// something is wrong bail out now
			Vendor::logger(Vendor::LOG_LEVEL_ERROR, 'Missing data! err_msg:'.$this->err_msg);
			//echo json_encode(array('error'=>$this->error, 'err_msg'=>$this->err_msg));
			//require($this->html_file);
			require($this->help_file_html);
			return;
		}
		
		// create our job object to save query results
		$job = $this->job = new ComplexJob();
		
		switch($this->cmd) {
			case 'view_all_trans':
				$this->createViewTransJob();
				break;
			case 'view_trans_detail':
				$this->createTransDetailJob($this->transID);
				break;
			case 'process':
				$this->createTransRowJob($this->transID);
				break;
			case 'reversal':
				$this->createReversalTransJob($this->transID);
				break;
			case 'refund':
				$this->createRefundTransJob($this->transID);
				//$this->appInstance->createRefundTransaction($this->transID, null);
				break;	
		}
		
		// run our job
		//$app->job();
		// we sleep for $req->sleep_time seconds to give the auto reversal timer enough time to
		// fire if the remote end does not respond
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.'->transID:'.$this->transID.' being put to sleep for '.$this->sleep_time.' seconds');

		// sleep for $sleep_time seconds to give the query time to execute
		// if the sleep() method is called outside of the run() method then the
		// second parameter must be true
		$this->sleep($this->sleep_time, true);
	}
	
	/**
	 * 
	 */
	public function run() {
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'RealTimeTransRequest->run() executing');
		$req = $this;
		$app = $req->appInstance;
		
		// we need 1, 2, or 3 bits of information to process
		// 1) (required) the id of the transaction from the DB
		// 2) (optional) the Track2 data (TK2 cannot be stored in the DB)
		// 3) (optional) the card security code (cannot be stored in DB)
		
//		// check our job result
//		if (!is_object($this->job)) {
//			// our job is not an object something went wrong
//			Vendor::logger(Vendor::LOG_LEVEL_WARNING, __FILE__.':'.__METHOD__.':'.__LINE__.'$this->job is not an object');
//			echo json_encode(array('error'=>1, 'err_msg'=>'$this->job is not an object!', '$this->job'=>$this->job));
//			return;
//		}
		
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' $this->cmd:"'.$this->cmd.'"');
		switch($this->cmd){
			case 'view_all_trans':
				require($this->trans_view_html);
				break;
			case 'view_trans_detail':
				// this is a single transaction detail
				//$detail_row = $this->job->getResult('trans_detail');
				$detail_row = $app->job->getResult($req->last_job_name);
				Vendor::logger(Vendor::LOG_LEVEL_INFO, ' using $job_name:'.$req->last_job_name);
				if ($this->trans_detail_div_only) {				
					try{
						require_once('RealTimeTransaction.class.php');
						require_once('Transaction.php');
						$transObj = new RealTimeTransaction($detail_row[0]);
						echo $transObj->outputHtmlView();
					}catch(Exception $e){
						Vendor::logger(Vendor::LOG_LEVEL_NOTICE, __METHOD__.' EXCEPTION caught! $e:'.$e);
					}
				} else {
					require($this->trans_detail_html);
				}
				break;
			case 'process':
			case 'refund':
			case 'reversal':
				//echo '<pre>'.print_r($req->job->getResults('trans_msg'), true).'</pre>';
				//echo '<pre>'.print_r($req->job->results, true).'</pre>';
				$this->createJSONISO8583Response($req->job->getResult('trans_msg'));
				break;
			default:
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'About to require():'.$this->html_file);
				require($this->help_file_html);
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __FILE__.':'.__METHOD__.':'.__LINE__);
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' completed');
	}
	
	/**
	 * createJSONISO8583Response() - output a JSON object with important details
	 * of the transaction
	 * @param ISO8583Trans $iso - the ISO8583 Transaction object to work with
	 */
	private function createJSONISO8583Response($iso) {
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Creating JSON Response from iso:'.get_class($iso));
		//if (is_object($iso) && )
		$resp = new stdClass();
		$resp->response_code = 'TR'; // set the default as timeout
		$resp->approved = false;
		if (is_object($iso)) {
			if (method_exists($iso, 'getDataForBit')) {
				$resp->response_code = $iso->getDataForBit(39);
			}
			// if we have a TIMEOUT_REVERSAL message then the response_code is '00'
			// but that simply means the remote end received our REVERSAL.
			if($iso->_trans_type === ISO8583Trans::TRANS_TYPE_TIMEOUT_REVERSAL) {
				$resp->response_code = 'TR';
			}
			$resp->approved = ($resp->response_code === '00')?true:false;
			$resp->avs_response = $iso->avs_response;
			$resp->cvvs_response = $iso->cvc_response;
			$resp->trans_id = $iso->original_trans_id;
		}
		echo json_encode($resp);
	}
	
	/**
	 * Check if the required values are present in the request
	 */
	private function valuesPresentInRequest(){
		$req = $this->req_params;
		$req_values = array();
		// check if we are trying to display current transactions
		if (array_key_exists('cmd', $req)) {
			switch($req['cmd']) {
				case 'view_trans_detail':
					$req_values[] = 'transID';
					if (array_key_exists('detail_div_only', $req) && $req['detail_div_only'] === 'true') {
						$this->trans_detail_div_only = true;
					}
					$this->cmd = 'view_trans_detail';
					break;
				case 'view_all_trans':
					$this->cmd = 'view_all_trans';
					break;
				case 'reversal':
					$req_values[] = 'transID';
					$this->cmd = 'reversal';
					break;
				case 'process':
					$req_values[] = 'transID';
					$req_values[] = 'cvc';
					$req_values[] = 'track2';
					$this->cmd = 'process';
					break;
				case 'refund':
					$req_values[] = 'transID';
					$this->cmd = 'refund';
					break;
			}
		} else {
			// output a simple help page
			$this->error = 1;
			$this->err_msg = "Command missing! Please see ".__METHOD__.':'.__LINE__;
		}
		
		// check for required values
		for($i =0; $i < count($req_values);$i++) {
			$val = $req_values[$i];
			if (!array_key_exists($val, $req)){
				$this->error = 1;
				$this->err_msg = 'No value for:'.$val;
			}else {
				$this->$val = $req[$val];
			}
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' $this->transID:'.$this->transID);

	}
	
	/**
	 * Create a transaction from the record stored in the DB and send the 
	 * transaction over the wire to FirstData
	 * @param int $id - DB row id of the transaction to process
	 * @return null
	 */
	private function createTransRowJob($id){
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' called with argument:'.$id);
		$app = $this->appInstance;
		$req = $this;
		$track2 = $req->req_params['track2'];
		$cvc = $req->req_params['cvc'];
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$app is of type:'.get_class($app).' in '.__METHOD__);
		$app->createISOandSend($app, $req, $id, $track2, $cvc);
	}
	
	/**
	 * createViewTransJob() - create a job to view all transactions
	 */
	public function createViewTransJob(){
		$app = $this->appInstance;
		$req = $this;
		//$q = $app->view_trans_query;
		$min_trans_id = (array_key_exists('min_trans_id', $req->req_params))?$req->req_params['min_trans_id']:0;
		$after_date = (array_key_exists('after_date', $req->req_params))?$req->req_params['after_date']:'1970-01-01';
		$q = SQL::viewAllTransQuery($min_trans_id, $after_date, $app->trans_table);
		$app->createJobFromQuery($app, $req, $min_trans_id.'-view_trans', $q, true);
	}
	
	/**
	 * createTransDetailJob() - View details of the transaction identified by 
	 * the given id
	 * @param int $id - DB record id of the transaction to view
	 */
	public function createTransDetailJob($id) {
		$app = $this->appInstance;
		$req = $this;
		$q = SQL::singleTransDetailQuery($id, $app->trans_table);
		$app->createJobFromQuery($app, $req, $id.'-trans_detail', $q, true);
	}
	
	/**
	 * createReversalTransJob() - pull info on a transaction and create a new
	 * transaction to reverse it
	 * @param int $id - DB record id of the id we will reverse
	 * @param int $type - named constant from ISO8583Trans class
	 */
	public function createReversalTransJob($id, $type = ISO8583Trans::TRANS_TYPE_REVERSAL) {
		$app = $this->appInstance;
		$req = $this;
		$q = SQL::singleTransDetailQuery($id, $app->trans_table);
		$app->createJobFromQuery($app, $req, $id.'-reversal_trans', $q, false, function($result) use($app, $req, $type, $id){
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'We are inside the createReversalTransJob() job callback!');
			//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Need to build a reversal DB record from data:'.print_r($result, true));
			$orig_tr = $result[0];
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Reversal trans original receipt_number:'.$orig_tr['receipt_number']);
			$q = SQL::buildQueryForReversal($result[0], $type, $app->trans_table);
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
	 * createRefundTransJob() - create a new transaction that refunds the given
	 * transaction
	 * @param int $id - DB record id of the transaction we wish to refund
	 */
	public function createRefundTransJob($id) {
		$app = $this->appInstance;
		$req = $this;
		$q = SQL::refundOriginalTransQuery($id, $this->appInstance->trans_table);
		$app->createJobFromQuery($app, $req, $id.'-refund_trans', $q, false, 
				function($result) use($app, $req, $id){
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Inside the refund_trans job callback $result:'.print_r($result, true));
			$q = SQL::buildQueryForRefund($result[0]);
			$app->createJobFromQuery($app, $req, $id.'-process_refund', $q, false,
					function($result) use($app, $req){
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Inside the process_refund job callback! $result:'.$result);
				// create and send a new ISO8583 message from our query
				$app->createISOandSend($app, $req, $result);
			});
		});
	}
	
	/**
	 * disableCaching() - send headers to let the remote host know not to cache 
	 * this page
	 */
	private function disableCaching(){
		// set headers to prevent caching
		try {
			$this->header("Cache-Control: no-cache, must-revalidate"); // HTTP 1.1
			$this->header("Pragma: no-cache"); // HTTP 1.0
			$this->header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
		} catch (Exception $e) {}
	}
	
	/**
	 * importRequestValues() - parse a HTTP QUERY_STRING and retrieve the key/val pairs
	 * @param String $req_attrs - the query string from the HTTP Request
	 * @return Array[] - key->value pairs from the HTTP Request
	 */
	public static function importRequestValues($req_attrs){
		$req_params = array();
		// grab our request, GET, and POST
		if (count($req_attrs->request) > 0) {
			foreach($req_attrs->request as $key=>$val) {
				$req_params[$key] = $val;
			}
		}
		if (count($req_attrs->get) > 0) {
			foreach($req_attrs->get as $key=>$val) {
				$req_params[$key] = $val;
			}
		}
		if (count($req_attrs->post) > 0) {
			foreach($req_attrs->post as $key=>$val) {
				$req_params[$key] = $val;
			}
		}
		// check for the server['QUERY_STRING'] value; this will be present if
		// we are parsing a bare object instead of the HTTP Request object
		if (property_exists($req_attrs, 'server')) {
			if (is_array($req_attrs->server) && array_key_exists('QUERY_STRING', $req_attrs->server)) {
				$ta = explode('&', $req_attrs->server['QUERY_STRING']);
				foreach($ta as $key=>$val) {
					if (stristr($val, '=')) {
						$key = substr($val,0,strpos($val, '='));
						$req_params[$key] = substr($val, strlen($key)+1);
					} else {
						$req_params[$val] = '';
					}
				}
			}
		}
		return $req_params;
	}
	
}
?>
