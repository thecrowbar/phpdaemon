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
	.cb{
		clear: both;
	}
	.bold{
		font-weight: bold;
	}
	#trans_detail{
		display: none;
		line-height: 2em;
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
<script type="text/javascript" src="/js/UT.core.js"></script>
<script type="text/javascript" src="/js/RealTimeTrans.js"></script>
<title>Transaction Processing</title>
</head>
<body>
	<div id="content">
		<h2>Transaction Processing</h2>
		<?php
		// include our debuggin output class (kint) if available
		if (is_dir('kint') && file_exists('kint/Kint.class.php')) {
			require_once('kint/Kint.class.php');
		}
		require_once('Transaction.php');
		require_once('RealTimeTransaction.class.php');
		// create an empty $trans array that gets overwritten by the real data
		// if there is any
		$trans = array(
			0 => array('No Results'=>'No transactions found',
				'id' => -1)
		);
		$job_name = 'trans_detail';
		
		// get our trans array from the job name
		if (is_array($this->job->getResult($job_name))) {
			if (count($this->job->getResult($job_name)) > 0 ) {
				$trans = $this->job->getResult($job_name);
			}
		}
		
		// display our transaction detail
		$detail_row = $trans[0];
		Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$trans:'.print_r($detail_row, true));
		
		$transObj = new RealTimeTransaction($detail_row);
		echo "<div id='transaction_detail'>\n";
		echo $transObj->outputHtmlView();
		?>
		<br />
		<input type="hidden" id="transID" value="<?php echo $transObj->id ?>" />
		<button id="void_transaction">Void (Full Auth Reversal)</button>
	</div>
</body>
</html>