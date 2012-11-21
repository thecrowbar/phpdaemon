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
	public $timeout = 600;
	/**
	 * Log file to record all incoming data to
	 * @var String
	 */
	public $logfile = '/var/log/phpdaemon/stdin.log';
	
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
			Daemon::log(__METHOD__.' timer callback firing! About to send keep-alive ISO8583 message');
			$conn->ping();
		}, 1e6 * 90);
		
		// make sure we fire any callbacks on the parent objects
		parent::onReady();
	}
	
	/**
	 * Event handler that fires when the client connects to the remote server
	 * @param Closure $cb - callback to execute when the client connects
	 */
	public function onConnected($cb){
		if (daemon::$debug) {
			Daemon::log(__METHOD__.' called. Connected to host:'.$this->host);
		}
		
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
		if (Daemon::$debug) {
			$file = fopen($this->logfile, 'a');
			if ($file !== false) {
				fwrite($file, $buf);
				fwrite($file, "\n\n\n");
				fclose($file);
			}
			Daemon::log('Received '.strlen($buf).' bytes of data. Adding to buffer. Buffer length currently:'.strlen($this->buf));
		}
		
		// apend new data to our input buffer
		$this->buf .= $buf;
		
		// start a timer to send keep alive messages
		Timer::setTimeout($this->keepaliveTimer);
		
		// check if we have enough bytes for a packet
		if (strlen($this->buf) >= $this->min_pkt_size) { return; }
		
		// find our packet start
		$pkt_start = strpos($this->buf, $this->pkt_start_bytes);
		if ($pkt_start !== false) {
			if (Daemon::$debug) {
				Daemon::log('Found packet start at offset: '.$pkt_start);
			}
			// good we found a packet, get our size
			$ta = unpack('n', binarySubstr($this->buf, $pkt_start+strlen($this->pkt_start_bytes),$this->pkt_size_bytes));
			$payload_size = $ta[1];
			$pkt_size = $payload_size + $this->min_pkt_size;

			// check our buffer to make sure we have the entire packet
			if (strlen($this->buf) < $pkt_size) { return; }

			// check our packet end bytes
			if (binarySubstr($this->buf, ($pkt_start + $pkt_size - strlen($this->pkt_end_bytes)), strlen($this->pkt_end_bytes))){
				// all packet checks passed. Fire event and send packet for processing
				$pkt = binarySubstr($this->buf, $pkt_start, $pkt_size);
				// remove this packet from the buffer
				$this->buf = binarySubstr($this->buf, $pkt_start + strlen($pkt));
				$this->event('data_recvd', $pkt);
			}			
		}
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
			Daemon::log('No handler defined for event:'.$name);
		}
	}
	
	/**
	 * Attach a callback function to a named event
	 * @param String $event to attach callback to
	 * @param callback $cb
	 */
	public function addEventHandler($event, $cb) {
		if (is_callable($cb) && Daemon::$debug){
			Daemon::log('Adding a callback for event: '.$event);
		}
		if (!isset($this->eventHandlers[$event])) {
			$this->eventHandlers[$event] = array();
		}
		// make sure we do not add the same callback multiple times
		if (!in_array($cb, $this->eventHandlers[$event])) {
			$this->eventHandlers[$event][] = $cb;
		}
		if (Daemon::$debug) {
			Daemon::log('There are now '.count($this->eventHandlers[$event]).' handlers defined for event: '.$event);
		}
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
		Timer::setTimeout($this->keepaliveTimer);
		
		try{
			//$send_result = $this->requestByKey(null, $ISO8583Msg, $cb);
			$send_result = $this->write($data);
			
			if ($send_result) {
				// our transaction was sent
				$this->event('data_sent', strlen($data));
			} else {
				// our transaction failed...
				Daemon::log('Failed to send data using host '.$this->hostReal.':'.$this->port);
				$this->connected = false;
			}
		}catch(Exception $ex) {
			Daemon::log('Exception caught while trying to send data! Exception:'.$ex);
		}
		
	}
	
}
?>
