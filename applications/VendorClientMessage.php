<?php
/**
 * VendorClientMessage - simple class to recreate object sent to JavaScript
 * over WebSocket
 *
 * @author jcrow
 */
class VendorClientMessage {
	public $command = "";
	public $trans_id = -1;
	public $response = "Response Text";
	public $auth_iden_resp = "";
	public $response_code = "";
}

?>
