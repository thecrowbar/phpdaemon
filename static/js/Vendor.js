
$(document).ready(function(){
	// attach our button click handlers
	$('#create_websocket').on('click', function(){create('VendorWS')});
	$('#send_ping').on('click', function(){ws.send('ping')});
	$('#close_websocket').on('click', function(){ws.close()});
	$('#send_command').on('click', function(){
		ws.send($('#command').val())
	});
	$('#send_object').on('click', function(){sendObject({command:'send_trans',trans_id:'1234'}, 'VendorWS')});
	$('#send_text').on('click', sendText);
	// attach the click handler to the table rows so each trans can be submitted
	// by clicking on the correct HTML table row
	$('.pending_trans').each(function(){
		$(this).on('mouseover',function(){
			$(this).addClass('highlight');
		});
		$(this).on('mouseout',function(){
			$(this).removeClass('highlight');
		});
		$(this).on('click', function(tid){
			// check if this row has already been processed; if so ask about a refund
			var row_tid = $(this).attr('trans_id');
			var title_str = $(this).attr('title');
			var obj = {
				command: '',
				trans_id: row_tid,
				title: title_str
			}
			var resp_elem = $(this).find('[col_name=response_code]');
			if (resp_elem.length < 1) {
				resp_elem = $(this).find('[col_name=rsp_code]');
			}
			var resp_code = resp_elem.html();
			if (resp_code.length > 0) {
				if(confirm('This transaction has already been processed. Would you like to refund it?')) {
					obj.command = 'refund_trans';
				}
			} else {
				// send the trans action if this row is clicked
				obj.command = 'send_trans';

				
			}
			sendObject(obj, 'VendorWS');
		})
	});
	
	// attach our table sorter
	$('#pending_trans').tablesorter({
		debug: false,
		theme: 'blue',
		widgets: ['zebra']
	});
});
