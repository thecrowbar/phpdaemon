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
	/**
	 * Flag used to signal the discarding of received data. This is useful when
	 * testing. For production use this should be false.
	 * @var Bool
	 */
	public $discardResponses = false;
	/**
	 * Flag to indicate if response packets should be held for testing. For 
	 * production this should be false.
	 * @var Bool
	 */
	public $holdResponses = false;
	/**
	 * The number of packets to hold before receiving the last one. This is
	 * for testing only and is ignored if $holdResponses is false. FD wants to see
	 * at least 3 time-Out-Reversal packets before they release the que and 
	 * send a single response.
	 * @var Int
	 */
	public $holdFloodCount = 4;
	/**
	 * Holds packets held during testing of auto reversal due to timeout
	 * @var Array[]
	 */
	public $heldPackets = array();
	
	/**
	 * **WARNING** If enabled, this will resend some transactions with ~33% resent
	 * @var Bool
	 */
	public $simulate_duplicate_trans = false;
	
	/**
	 * Array that holds recently sent packets for duplicate testing
	 * @var String[]
	 */
	public $sentPackets = array();
	
	/**
	 * Timer that fires after 2 seconds to resend packets. It works to simulate 
	 * duplicate transaction issues
	 * @var Timer
	 */
	public $resendTimer;
	
	/**
	 * Flag to indicate that no data should actually be sent. Useful for testing
	 * @var bool
	 */
	public $do_not_send = false;
	
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
	 * The main application that created this connection
	 * @var Vendor
	 */
	public $appInstance;
	
	public function init(){
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' called with no arguments');
		// set our log file names to include the class name
		//Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' called from:'.get_class());
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' called from:'.get_class().' has parent:'.  get_parent_class());
	}
	
	/**
	 * Called when the connection is connected and ready to process data
	 * @param Vendor $appInstance - the appinstance that crated this connection
	 */
	public function onReady() {
		// create a timer to fire keep alive messages, default timeout = 90 secs
		$conn = $this;
		//Vendor::logger(Vendor::LOG_LEVEL_DEBUG,__CLASS__.print_r($this, true));
		$this->keepaliveTimer = setTimeout(function($timer) use($conn) {
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.' timer callback firing!');
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
		if ($this->discardResponses) {
			if ($this->discard_count)
			Vendor::logger(Vendor::LOG_LEVEL_EMERGENCY, __METHOD__.':'.__LINE__.' Not processing received data to test auto reversal');
		} else if($this->holdResponses){
			Vendor::logger(Vendor::LOG_LEVEL_EMERGENCY, __METHOD__.':'.__LINE__.' Holding packets to simulate link problems');
			$this->heldPackets[] = binarySubstr($this->buf, $pkt_start, $pkt_size);
			if (count($this->heldPackets) >= $this->holdFloodCount) {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Sending last packet out of  '.count($this->heldPackets).' packets for testing');
//				while(count($this->heldPackets) > 0) {
//					$this->event('data_recvd', array_shift($this->heldPackets));
//				}
				$this->event('data_recvd', array_pop($this->heldPackets));
				$this->heldPackets = array();
			}
		}else {
			$this->event('data_recvd', binarySubstr($this->buf, $pkt_start, $pkt_size));
		}
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
		$this->event('keep-alive_timeout');
		$this->sendData(VendorMessage::getTCPKeepAlive());
	}
	
	/**
	 * Write data to our connection and fire event
	 * @param String $data - the binary string of data to send 
	 * @param Closure $cb - function to execute after data is sent
	 */
	public function sendData($data, $cb = null) {		
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

			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Writing '.strlen($data).' bytes of data.');
			//$send_result = $this->requestByKey(null, $ISO8583Msg, $cb);
			if ($this->do_not_send){
				Vendor::logger(Vendor::LOG_LEVEL_ALERT, 'Not sending data due to the $do_not_send === true');
				// reset our keepaliveTimer
					Timer::setTimeout($this->keepaliveTimer);
			} else {
				// check if we should simulate duplicate transactions
				// ignore transactions packets smaller than 51 bytes, that is the size of the keep-alive message
				if ($this->simulate_duplicate_trans === true && strlen($data) > 51) {
					if (count($this->heldPackets) > 9) {
						array_shift($this->heldPackets);
					}
					$this->heldPackets[] = $data;
					$rand = rand(0,9);
					if ($rand < 6) {
						// resend a packet
						if (array_key_exists($rand, $this->heldPackets)){
							Vendor::logger(Vendor::LOG_LEVEL_CRITICAL, 'Attempting to resend a packet to simulate problems');
							$pkt = $this->heldPackets[$rand];
							$vendorClientCon = $this;
							$this->resendTimer = setTimeout(function($timer) use($pkt, $vendorClientCon){
								Vendor::logger(Vendor::LOG_LEVEL_INFO, 'Resend packet timer firing');
								$vendorClientCon->sendData($pkt);
							}, 2 * 1e6);
						}
					}
				}
				$send_result = $this->write($data);
				if ($send_result) {
					// our transaction was sent
					$this->event('data_sent', strlen($data));
					// if we sent our data, reset our keepaliveTimer
					Timer::setTimeout($this->keepaliveTimer);
				} else {
					// our transaction failed...
					Vendor::logger(Vendor::LOG_LEVEL_CRITICAL, 'Failed to send data using host '.$this->hostReal.':'.$this->port);
					$this->connected = false;
				}
			}
			
			// execute our callback (if any)
			if (isset($cb) && is_callable($cb)) {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG,' About to call user function at '.__FILE__.':'.__LINE__);
				call_user_func($cb);
			} else {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, __METHOD__.': $cb not set or not callable!');
			}
				
		}catch(Exception $ex) {
			Vendor::logger(Vendor::LOG_LEVEL_CRITICAL, 'Exception caught while trying to send data! Exception:'.$ex);
		}
		
	}
	
	
	
}
?>
