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
	 * Called when the object is ready to process data
	 */
	public function onReady() {
		// create a timer to fire keep alive messages, default timeout = 90 secs
		$conn = $this;
		$this->keepaliveTimer = setTimeout(function($timer) use($conn) {
			Daemon::log('timer callback firing');
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
		
		// start the keep-alive timer 
		Timer::setTimeout($this->keepaliveTimer);
		
		parent::onConnected($cb);
		
	}
	
	/**
	 * Called when session finishes
	 * @return void
	 */
	public function onFinish() {
		parent::onFinish();
		$this->event('disconnect');
		
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
		
		
		// search our buffer for packet start bytes. If found attempt to get entire packet
		$pkt_start = 0;
		$pkt_size = 0;
		for ($i = 0; $i < strlen($this->buf); $i++) {
			if (binarySubstr($this->buf, $i, strlen($this->pkt_start_bytes)) === $this->pkt_start_bytes) {
				// we found our packet start 
				if (Daemon::$debug) {
					Daemon::log('Found packet start at offset: '.$i);
				}
				$pkt_start = $i;
				$ta = unpack('n',substr($this->buf,strlen($this->pkt_start_bytes),$this->pkt_size_bytes));
				$payload_size = $ta[1];
				$payload_start = $pkt_start + strlen($this->pkt_start_bytes) + $this->pkt_size_bytes;
				$payload_end = $payload_start + $payload_size;
				$pkt_size = strlen($this->pkt_start_bytes) + $this->pkt_size_bytes +
						$payload_size + strlen($this->pkt_end_bytes);
				
				// check to see if we have enough bytes for the entire message
				if ((strlen($this->buf) - $i) >= $pkt_size) {
					
					// good we have enough bytes for the complete message
					// check message end bytes
					if (binarySubstr($this->buf, $payload_end, strlen($this->pkt_end_bytes)) 
							== $this->pkt_end_bytes) {
						
						// basic message checks passed
						// fire event and pass this message off
						$pkt = binarySubstr($this->buf, $pkt_start, $pkt_size);
						// remove this packet from the buffer
						$this->buf = binarySubstr($this->buf, $i + strlen($pkt));
						$this->event('data_recvd', $pkt);
					}
				} else {
					if (Daemon::$debug) {
						Daemon::log('Not enough bytes in buffer for entire message');
					}
				}
				// break out of our for() loop looking for packet start
				break;
			}
		}
		
		// start a timer to send keep alive messages
		Timer::setTimeout($this->keepaliveTimer);
		
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
		$this->eventHandlers[$event][] = $cb;
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
				$this->event('data_sent');
			} else {
				// our transaction failed...
				Daemon::log('Failed to send data');
			}
		}catch(Exception $ex) {
			Daemon::log('Exception caught while trying to send data! Exception:'.$ex);
		}
		
	}
	
}
?>
