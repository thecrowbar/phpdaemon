<?php
/**
 * Connection class for our remote vendor. This class is responsible for sending
 * and receiving data. It also fires events to let other classes know about what 
 * is happenning
 * @event disconnect - fires when the connection terminates
 * @event data_sent - fires when data is sent over the connection
 * @event data_recvd - fires when data is available to process
 */
class VendorClientConnection extends NetworkClientConnection {
	/**
	 * Array of functions to call when an event is executed
	 * @var Array[] event name => callback function
	 */
	public $eventHandlers = array();
	public $size = 0;
	/**
	 * Number of seconds before keep alive timer will fire
	 * @var Int
	 */
	public $keepaliveTimeout = 600;
	/**
	 * Log file to record all incoming data to
	 * @var String
	 */
	public $logfile_in = '/opt/phpdaemon/log/stdin.log';
	
	/**
	 * Log file to record all outgoing data to
	 * @var String
	 */
	public $logfile_out = '/opt/phpdaemon/log/stdout.log';
	
	/**
	 * Timer that fires to send keep alive ISO8583 message over TCP
	 * @var Timer Object 
	 */
	public $keepaliveTimer;
	
	/**
	 * Bytes that indicate the start of a message in the TCP data
	 * @var String
	 */
	public $pkt_start_bytes = "\x02\x46\x44\x02";
	
	/**
	 * Bytes that indicate the end of a TCP message
	 * @var String
	 */
	public $pkt_end_bytes = "\x03\x46\x44\x03";
	
	/**
	 * Number of bytes to use as the packet size
	 * @var int
	 */
	public $pkt_size_bytes = 2;
	
	/**
	 * Smallest possible packet in bytes. Add pkt_start, pkt_size, & pkt_end bytes
	 * @var Int
	 */
	public $min_pkt_size = 10;
	
	/**
	 * Called when the connection is comnnected and ready to process data
	 */
	public function onReady() {
		// create a timer to fire keep alive messages, default timeout = 90 secs
		$conn = $this;
		$this->keepaliveTimer = setTimeout(function($timer) use($conn) {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' timer callback firing! About to send keep-alive ISO8583 message');
			$conn->ping();
		}, 1e6 * $this->keepaliveTimeout);
		
		// make sure we fire any callbacks on the parent objects
		parent::onReady();
	}
	
	/**
	 * Event handler that fires when the client connects to the remote server
	 * @param Closure $cb - callback to execute when the client connects
	 */
	public function onConnected($cb){
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' called. Connected to host:'.$this->host);
		
		// timeout only called in the onReady and stdin methods
		// start the keep-alive timer 
		//Timer::setTimeout($this->keepaliveTimer);
		
		parent::onConnected($cb);
		
	}
	
	/**
	 * Called when session finishes
	 * @return void
	 */
	public function onFinish() {
		parent::onFinish();
		$this->event('disconnect', $this->hostReal, $this->port);
		
		// remove our keep-alive timer to setop sending data to disconnected connection
		Timer::remove($this->keepaliveTimer);
	}
	
	/**
* Called when new data received
* @param string New data
* @return void
*/
	public function stdin($buf) {
		// append our data to any log file
		if (Vendor::$log_tcp_stream) {
			$file = fopen($this->logfile_in, 'a');
			if ($file !== false) {
				fwrite($file, $buf);
				//fwrite($file, "\n\n\n");
				fclose($file);
			}
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Received ' . strlen($buf) . ' bytes of data. Adding to buffer. Buffer length currently:' . strlen($this->buf));

		// apend new data to our input buffer
		$this->buf .= $buf;

		// start a timer to send keep alive messages
		Timer::setTimeout($this->keepaliveTimer);

		$startsize = strlen($this->pkt_start_bytes);
		$endsize = strlen($this->pkt_end_bytes);

		start:

		// check if we have enough bytes for a packet
		if (strlen($this->buf) < $this->min_pkt_size) {
			return;
		}

		// find our packet start
		if (($pkt_start = strpos($this->buf, $this->pkt_start_bytes)) === false) {
			return;
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Found packet start at offset: ' . $pkt_start);
		// good we found a packet, get our size
		list (, $payload_size) = unpack('n', binarySubstr($this->buf, $pkt_start + $startsize, $this->pkt_size_bytes));
		
		// calculate the entire pkt_size once, we use it several times
		$pkt_size = $startsize + $this->pkt_size_bytes + $payload_size + $endsize;

		// check our buffer to make sure we have the entire packet
		if (strlen($this->buf) < $pkt_start + $pkt_size) {
			return;
		}
		if (binarySubstr($this->buf, $pkt_start + $startsize + $this->pkt_size_bytes + $payload_size, $endsize) !== $this->pkt_end_bytes) {
			Vendor::logger(Vendor::LOG_LEVEL_NOTICE, 'Wrong End Bytes.');
			$this->finish();
			return;
		}
		$this->event('data_recvd', binarySubstr($this->buf, $pkt_start, $pkt_size));
		$this->buf = binarySubstr($this->buf, $pkt_start + $pkt_size);

		goto start;
	}
	public function checkPacket(){
		
	}
	
	/**
	 * Execute any defined callbacks for the given event
	 * @param String $name - the event name to fire
	 * @param Any $args - arguments to be passed to any defined event callbacks
	 */
	public function event() {
		$args = func_get_args();
		$name = array_shift($args);
		if (isset($this->eventHandlers[$name])) {
			foreach ($this->eventHandlers[$name] as $cb) {
				call_user_func_array($cb, $args);
			}
		} else {
			Vendor::logger(Vendor::LOG_LEVEL_WARNING, 'No handler defined for event:'.$name);
		}
	}
	
	/**
	 * Attach a callback function to a named event
	 * @param String $event to attach callback to
	 * @param callback $cb
	 */
	public function addEventHandler($event, $cb) {
		if (is_callable($cb)){
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Adding a callback for event: '.$event);
		}
		if (!isset($this->eventHandlers[$event])) {
			$this->eventHandlers[$event] = array();
		}
		// make sure we do not add the same callback multiple times
		if (!in_array($cb, $this->eventHandlers[$event])) {
			$this->eventHandlers[$event][] = $cb;
		}
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'There are now '.count($this->eventHandlers[$event]).' handlers defined for event: '.$event);
	}
	
	/**
	 * Send a keepalive message
	 */
	public function ping(){
		$this->sendData(VendorMessage::getTCPKeepAlive());
	}
	
	/**
	 * Write data to our connection and fire event
	 * @param String $data - the binary string of data to send 
	 */
	public function sendData($data) {		
		try{
			// append our data to any log file
			if (Vendor::$log_tcp_stream) {
				$file = fopen($this->logfile_out, 'a');
				if ($file !== false) {
					fwrite($file, $data);
					fwrite($file, "\n\n\n");
					fclose($file);
				}
			}
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Wrote '.strlen($data).' bytes of data.');
			//$send_result = $this->requestByKey(null, $ISO8583Msg, $cb);
			$send_result = $this->write($data);
			
			if ($send_result) {
				// our transaction was sent
				$this->event('data_sent', strlen($data));
			} else {
				// our transaction failed...
				Vendor::logger(Vendor::LOG_LEVEL_CRITICAL, 'Failed to send data using host '.$this->hostReal.':'.$this->port);
				$this->connected = false;
			}
		}catch(Exception $ex) {
			Vendor::logger(Vendor::LOG_LEVEL_CRITICAL, 'Exception caught while trying to send data! Exception:'.$ex);
		}
		
	}
	
}
?>
