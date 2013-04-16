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
<title>Vendor Processing Draft for <?php echo $req->draft_date; ?></title>
</head>
<body>
	<div id="content">
		<h2>Processing Draft for <?php echo $req->draft_date; ?></h2>
		<?php
		if (is_array($this->job->getResult('submit_draft'))) {
			$trans = $this->job->getResult('submit_draft');
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this->app is of type:'.Vendor::get_type($this->app));
//			echo "<div style='height:250px; overflow:scroll; width:1000px;><pre>\n";
//			print_r($this->app);
//			echo "</pre></div>\n";
			$trans_count = count($trans);
			if ($trans_count < 1) {
				echo "<h4>Job:</h4><pre>";
				print_r($this->job);
				echo "</pre>\n";
				echo "<h4>\$this:</h4><pre>\n";
				print_r($this);
				echo "</pre>\n";
			}
		}else{
			echo "<pre>\n";
			print_r($this->job);
			echo "</pre>
				</div>
</body>
</html>\n";
			exit();
		}
		?>
		<h3>Found <?php echo $trans_count;?> transactions to process.</h3>
		<h4>Memory Limit (bytes): <?php echo ini_get('memory_limit'); ?></h4>
		<h4>Memory Usage (bytes): <?php echo memory_get_usage();?></h4>
		<h4>Memory Real Usage (bytes): <?php echo memory_get_usage(true);?></h4>
		<h4>Memory Peak Usage (bytes): <?php echo memory_get_peak_usage();?></h4>
		<h4>Memory Real Peak Usage (bytes): <?php echo memory_get_peak_usage(true);?></h4>
		<table>
			<thead>
				<tr>
					<th>user_name</th>
					<th>amount</th>
					<th>CC num</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$row_count = 0;
				foreach($trans as $t) {
					$class = ($row_count%2) ? 'even':'odd';
					echo "
					<tr class='{$class}'>
						<td>{$t['user_name']}</td>
						<td>{$t['trans_amount']}</td>";
					// decrypt CC
					$ccnum = Vendor::decrypt_data($t['pri_acct_no']);
					if (is_array($ccnum)) {
						// something went wrong with the decyption
						$ccnum = $ccnum['error_msg'];
					}
					echo"
						<td>{$ccnum}</td>
					</tr>\n";
					$row_count++;
				}
				?>
			</tbody>
		</table>
		<button value='Process Draft!' onclick="location.href='/Vendor/?command=submit_draft&draft_date=<?php echo $req->draft_date ?>&process=true'">Process Draft!</button><br />
		Errors:<br />
		<pre><?php print_r($req->err_msg); ?></pre><br />
		$req->attrs:
		<pre><?php print_r($req->attrs); ?></pre><br />
		<h4>Memory Usage (bytes): <?php echo memory_get_usage();?></h4>
		<h4>Memory Real Usage (bytes): <?php echo memory_get_usage(true);?></h4>
		<h4>Memory Peak Usage (bytes): <?php echo memory_get_peak_usage();?></h4>
		<h4>Memory Real Peak Usage: <?php echo memory_get_peak_usage(true);?>b (<?php echo memory_get_peak_usage(true)/1024;?>k) (<?php echo memory_get_peak_usage(true)/(1024*1024);?>m)</h4>
	</div>
</body>
</html>