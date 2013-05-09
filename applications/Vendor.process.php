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
		if (is_array($this->app->job->getResult('submit_draft'))) {
			$trans = $this->app->job->getResult('submit_draft');
			// save our batch ids so we can set them as transmitted
			$batch_ids = array();
			echo "<h3>Found ".count($trans)." transactions to process.</h3>";
			Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this->app is of type:'.Vendor::get_type($this->app));
			if (method_exists($this->app, 'createMessage')) {
				Vendor::logger(Vendor::LOG_LEVEL_DEBUG, '$this->app has method createMessage!');
				foreach($trans as $t) {
					//Vendor::log(Vendor::LOG_LEVEL_DEBUG, '$t:'.print_r($t, true));
					// submit each transaction
					$this->app->createMessage($t['id'], null, $this);
					
					if (!in_array($t['batch_id'], $batch_ids)) {
						$batch_ids[] = $t['batch_id'];
					}
				}
				
				// now set the drafts as submitted to the bank
				$this->app->setBatchAsTransmitted($batch_ids);
				
			} else {
				Vendor::logger(Vendor::LOG_LEVEL_ERROR, '$this->app lacks method createMessage!!!!!!!!');
			}
			
		}else{
			echo "<h1>Error getting transactions to submit!</h1>";
			echo "<pre>\$this->job\n";
			print_r($this->job);
			echo "</pre>\n";
			echo "<pre>\$this->app->job\n";
			print_r($this->app->job);
			echo "</pre>\n";
		}
		?>

	</div>
</body>
</html>