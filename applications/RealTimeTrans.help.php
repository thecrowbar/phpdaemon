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
		<h2>Vendor Transaction Processing Help</h2>
		
		<p>There are four main ways to interact with the transaction processing system:</p>
		<ul>
			<li>View All Transactions: <a href="?cmd=view_all_trans">Test It</a></li>
			<li>View Transaction Detail: <a href="?cmd=view_trans_detail&transID=385">Try TransID=385</a> (This requires a transaction ID)</li>
			<li>Process Transaction: <a href="?cmd=process&transID=386">Try TransID=386</a> This also requires the track2 data, or cvc/cvv2 code data</li>
			<li>Reverse Transaction: <a href="?cmd=reversal&transID=387">Try TransID=387</a> Create a new reversal transaction from the given transID</li>
			<li>Refund Transaction: <a href="?cmd=refund&transID=388">Try TransID=388</a> Create a refund transaction from the given transID</li>
		</ul>
</div>
</body>
</html>