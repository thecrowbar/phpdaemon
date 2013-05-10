<?php
class VendorWebSocketRoute extends WebSocketRoute { 
	/**
	 * AppInstance of the parent of this object
	 * @var Vendor Object
	 */
	public $appInstance;
	/**
	 * A link back to this closure so I can send data back to web browser through
	 * websocket
	 * @var VendorWebSocketRoute Object
	 */
	public $ws;

	/**
	 * Called when new frame received.
	 * @param string Frame's contents.
	 * @param integer Frame's type.
	 * @return void
	 */
	public function onFrame($data, $type) {
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'data:'.$data.' received from websocket client');
		
		// attmept to JSON decode the frame. Try to recover the object
		$client_data = json_decode($data);
		if (is_object($client_data)) {
			// we have a good decode
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Decoded a JSON object! Object:'.print_r($client_data, true));
			// act on our object
			if ($client_data->command === 'send_trans') {
				// save this closure in the appInstance so it can return transaction
				// result to client over ws
				$this->appInstance->ws = $this;
				$this->appInstance->createISOandSend($this->appInstance, null, $client_data->trans_id);
			} else if($client_data->command === 'refund_trans'){
				// create and submit a refund transaction for the given transaction id
				$this->appInstance->ws = $this;
				$this->appInstance->createRefundTransaction($client_data->trans_id);
			}else {
				// unknown command
				$this->client->sendFrame('Unknown command:'.$client_data->command, 'STRING');
			}
		} else {
			$this->client->sendFrame('Error '.json_last_error().', trying to decode data', 'STRING');
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, 'Received object:'.print_r($client_data, true));
		}
	}
}
?>
