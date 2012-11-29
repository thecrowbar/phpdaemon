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
</style>
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.8.2/jquery.min.js"></script>
<link rel="stylesheet" href="//ajax.googleapis.com/ajax/libs/jqueryui/1.9.0/themes/base/jquery-ui.css" type="text/css" media="all" /> 
<script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.9.0/jquery-ui.min.js"></script>
<script src="/js/jquery.json-2.3.min.js"></script>
<script type="text/javascript" src="/js/Vendor.js"></script>
<title>phpdaemon Example</title>
</head>
<body>
	<div id="content">
		<h2>phpdaemon Example</h2>
		<?php
		if (is_array($this->job->getResult('pending_trans'))) {
			$trans = $this->job->getResult('pending_trans');
			// we have pending transactions to display
			// get the first five column names from the array
			$columns = array_keys($trans[0]);
			echo "<table class='border1' id='pending_trans'>
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
				$add_class = ' odd ';
				if ($row_count % 2) {
					$add_class = ' even ';
				}
				echo "<tr class='pending_trans {$add_class}' trans_id='{$trans[$i]['id']}' >";
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
		} else {
			echo "<pre>\n";
			print_r($this->job);
			echo "</pre>\n";
		}
		?>
	</div>
	<h4>This section just for testing. Remove for production code.</h4>
	<button onclick="create();">Create WebSocket</button>
	<button onclick="ws.send('ping');">Send ping</button>
	<button onclick="ws.close();">Close WebSocket</button>
	<div id="log" style="width:600px; height: 100px; border: 1px solid #999999; overflow:auto;"></div><br />
	<button onclick="ws.send('command');">Send Command</button><button onclick="sendObject({command:'send_trans',trans_id:'1234'});">Send Object</button><br />
	<button onclick="sendText();">Send Text</button><input type="text" name="command" id="command" />
</body>
</html>