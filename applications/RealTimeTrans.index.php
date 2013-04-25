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
	hr {
		width: 100%;
		clear:both;
	}
	.card_heading{
		text-align: center;
		width: 100%;
		background-color: #99CCFF;
		clear: both;
	}
	.bggrey{
		background-color: #CCCCCC;
	}
	#process_results{
		display: none;
	}
	.col1{
		float: left;
		width: 44%;
		clear: left;
	}
	.col2{
		float: left;
		width: 55%;
	}
	#transaction_detail{
		width: 725px;
		margin-right: auto;
		margin-left: auto;
		clear: both;
		line-height: 2em;
	}
</style>
<link rel="stylesheet" href="/css/tablesorter-theme-blue/style.css" type="text/css" media="all" />
<script type="text/javascript" src="/js/jquery-1.8.2.min.js"></script>
<link rel="stylesheet" href="//ajax.googleapis.com/ajax/libs/jqueryui/1.9.0/themes/base/jquery-ui.css" type="text/css" media="all" /> 
<script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jqueryui/1.9.0/jquery-ui.min.js"></script>
<script type="text/javascript" src="/js/jquery.json-2.3.min.js"></script>
<script type="text/javascript" src="/js/jquery.tablesorter.min.js"></script>
<script type="text/javascript" src="/js/websocket.js"></script>
<script type="text/javascript" src="/js/UT.core.js"></script>
<script type="text/javascript" src="/js/RealTimeTrans.js"></script>
<title>Vendor Transaction Processing</title>
</head>
<body>
	<div id="content">
		<h2>Vendor Transaction Processing</h2>
		<?php
		// include our debuggin output class (kint) if available
		if (is_dir('kint') && file_exists('kint/Kint.class.php')) {
			require_once('kint/Kint.class.php');
		}
		// create an empty $trans array that gets overwritten by the real data
		// if there is any
		$trans = array(
			0 => array('No Results'=>'No transactions found',
				'id' => -1)
		);
		// the type of page to display is dependant on $this->cmd
		switch($this->cmd) {
			case 'pending_trans':
				$job_name = 'pending_trans';
				break;
			case 'single_batch':
				$job_name = 'single_batch';
				break;
			case 'view_all_trans':
				$min_trans_id = (array_key_exists('min_trans_id', $req->req_params))?$req->req_params['min_trans_id']:0;
				$job_name = $min_trans_id.'-view_trans';
				break;
			default:
				$job_name = '';
		}
		
		$job_name = $req->last_job_name;
		Vendor::logger(Vendor::LOG_LEVEL_INFO, ' using $job_name:'.$job_name);
		// get our trans array from the job name
		if (is_array($app->job->getResult($job_name))) {
			if (count($app->job->getResult($job_name)) > 0 ) {
				$trans = $app->job->getResult($job_name);
			}
		}
		
		// display our $trans array
		$columns = array_keys($trans[0]);
		echo "<table class='border1 tablesorter' id='pending_trans'>
			<thead>
				<tr>";
		foreach($columns as $column) {
			echo "\t\t\t\t\t\t<th>{$column}</th>\n";
		}
		echo "					</tr>
			</thead>
			<tbody>\n";
		$row_count = 0;
		for ($i =0; $i < count($trans); $i++) {
			echo "<tr class='pending_trans ' trans_id='{$trans[$i]['id']}' >";
			foreach($columns as $col) {
				echo "\t\t\t\t\t\t<td col_name='{$col}'>{$trans[$i][$col]}</td>\n";
			}
			echo "
				</tr>\n";
			$row_count++;
		}

		echo "
			</tbody>
		</table><br />\n";
			
		if ($job_name === '') {
			echo "Example modes of operation:<br />\n";
			echo "View all transactions:	RealTimeTrans/?cmd=view_all_trans<br />\n";
			echo "View single trans detail: RealTimeTrans/?cmd=view_trans_detail&transID=xxx<br />\n";
			echo "Refund transaction: RealTimeTrans/?cmd=refund&transID=xxx<br />\n";
			echo "Reverse transaction: RealTimeTrans/?cmd=reversal&transID=xxx<br />\n";
			echo "Process transaction: RealTimeTrans/?cmd=process&transID=xxx&track2=xxx&cvc=xxx<br />\n";
				
		}
		?>
	</div>
	<!-- Test change 1 2 3 -->
	<div>
		<h4>This section just for testing. Remove for production code.</h4>
		<button id="create_websocket">Create WebSocket</button>
		<button id="send_ping">Send ping</button>
		<button id="close_websocket">Close WebSocket</button>
		<div id="log" style="width:600px; height: 100px; border: 1px solid #999999; overflow:auto;"></div><br />
		<button id="send_command">Send Command</button>
		<button id="send_object">Send Object</button><br />
		<button id="send_text">Send Text</button>
		<input type="text" name="command" id="command" />
	</div>
	<?php 
	// if the kint class (d() function) is available output our objects for
	// easy display/debugging
	if (function_exists('d')) {
		echo "<div><pre>";
		d($req->attrs);
		echo "</pre></div>\n";
		echo "<div><pre>";
		d($req->job);
		echo "</pre></div>\n";
	}
	?>
	<div id="dialog">This is where the detail trans HTML will go</div>
</body>
</html>