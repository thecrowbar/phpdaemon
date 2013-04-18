$(document).ready(function(){
	
	//debugger;
	UT.namespace('RealTimeTrans');
	
	UT.RealTimeTrans.websocketRoute = 'RealTimeTransWS';
	
	UT.RealTimeTrans.processVoid = function(){
		var transID = $('#transID').val();
		var url = '/RealTimeTrans/?void_trans&transID='+transID;
		alert('We should void using this url:'+url);
	}
	
	UT.RealTimeTrans.Refund = function(transID){
		$.ajax({
			url: '/RealTimeTrans/?cmd=refund&transID='+transID,
			dataType: 'json',
			success: function(data, jqXHR){
				var msg = 'jqXHR:'+JSON.stringify(jqXHR, null, 4);
				msg = msg + '\ndata:'+JSON.stringify(jqXHR,null, 4);
			},
					
		})
	}
	// attach event handlers
	$('#void_transaction').on('click', UT.RealTimeTrans.processVoid);
		// attach our button click handlers
	$('#create_websocket').on('click', function(){create(UT.RealTimeTrans.websocketRoute)});
	$('#send_ping').on('click', function(){ws.send('ping')});
	$('#close_websocket').on('click', function(){ws.close()});
	$('#send_command').on('click', function(){
		ws.send($('#command').val())
	});
	$('#send_object').on('click', function(){sendObject({command:'send_trans',trans_id:'1234'},UT.RealTimeTrans.websocketRoute)});
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
			var row_tid = $(this).attr('trans_id');
			// get our detail HTML view through AJAX
			var transData = {
				cmd:'view_trans_detail',
				detail_div_only: 'true',
				transID: row_tid
			}
			$.ajax({
				url: '/RealTimeTrans/',
				data: transData,
				type: 'GET',
				dataType: 'html',
				success: function(data, jqXHR) {
					// load the response into our div
					$('#dialog').html(data);
					//$('#dialog').append(data);
					
					// inject some buttons into the detail view
					$('#trans_detail_buttons').append('<button id="refund_trans">Refund</button>');
					$('#trans_detail_buttons').append('<button id="reverse_trans">Full Auth/Reversal</button>');
					$('#refund_trans').on('click', function(){
						var transID = $('#transID').val();
						alert('We should refund this transaction! TransID:'+transID);
						$.ajax({
							url: '/RealTimeTrans/',
							data: {
								cmd: 'refund',
								transID: transID
							},
							type: 'GET',
							dataType: 'json',
							success: function(data, jqXHR) {
								alert('AJAX Success! data:'+JSON.stringify(data, null, 4));
							},
							error: function(jqXHR, textStatus, errorThrown) {
								alert('AJAX Error! jQXHR:'+JSON.stringify(jqXHR, null, 4));
							}
						})
					});
					$('#reverse_trans').on('click', function(){
						var transID = $('#transID').val();
						//alert('We should Full Auth Reversal this transaction! TransID:'+transID);
						$.ajax({
							url: '/RealTimeTrans/',
							data: {
								cmd: 'reversal',
								transID: transID
							},
							type: 'GET',
							dataType: 'json',
							success: function(data, jqXHR) {
								alert('AJAX Success! data:'+JSON.stringify(data, null, 4));
							},
							error: function(jqXHR, textStatus, errorThrown) {
								alert('AJAX Error! jQXHR:'+JSON.stringify(jqXHR, null, 4));
							}
						});
					});
					// show our dialog
					$('#dialog').dialog({
						draggable: false,
						height: '700',
						modal: true,
						resizeable: false,
						width: '900'
					});
				},
				error: function(jqXHR, errorThrown, textStatus) {
					var msg = 'jqXHR:'+JSON.stringify(jqXHR, null, 4);
					msg = msg + '\ntextStatus:'+textStatus+', errorThrown:'+errorThrown;
					alert(msg);
				}
			});
			// display the trans detail in the dialog div
			

			// check if this row has already been processed; if so ask about a refund
			
//			var title_str = $(this).attr('title');
//			var obj = {
//				command: '',
//				trans_id: row_tid,
//				title: title_str
//			}
//			var resp_elem = $(this).find('[col_name=response_code]');
//			if (resp_elem.length < 1) {
//				resp_elem = $(this).find('[col_name=rsp_code]');
//			}
//			var resp_code = resp_elem.html();
//			if (resp_code.length > 0) {
//				if(confirm('This transaction has already been processed. Would you like to refund it?')) {
//					obj.command = 'refund_trans';
//				}
//			} else {
//				// send the trans action if this row is clicked
//				obj.command = 'send_trans';
//
//				
//			}
//			sendObject(obj, UT.RealTimeTrans.websocketRoute);
		})
	});
	
	// attach our table sorter
	$('#pending_trans').tablesorter({
		debug: false,
		theme: 'blue',
		widgets: ['zebra']
	});
});


