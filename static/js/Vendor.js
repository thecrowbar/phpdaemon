var ws;
var ws_connected = false;
function create() {
	// Example
	ws = new WebSocket('ws://'+document.domain+':8047/VendorWS');
	ws.onopen = function() {
		document.getElementById('log').innerHTML += 'WebSocket opened <br/>';
		ws_connected = true;
	}
 	ws.onmessage = function(e) {
		//debugger;
		var resp = $.parseJSON(e.data);
		if (resp.hasOwnProperty('trans_id')) {
			// find the id record on screen
			var scr_rec = $('tbody').find('[trans_id='+resp.trans_id+']');
			// update the response text
			$(scr_rec).find('[col_name=auth_iden_response]').html(resp.auth_iden_resp);
			// update the response_code
			$(scr_rec).find('[col_name=response_code]').html(resp.response_code);
			//debugger;
		}
		document.getElementById('log').innerHTML += 'WebSocket message: '+e.data+' <br/>';
	}
	ws.onclose = function() {
		document.getElementById('log').innerHTML += 'WebSocket closed <br/>';
		ws_connected = false;
	}
}

function sendText() {
	var text = jQuery('#command').val();
	var result = ws.send(text);
	alert('result is of type:'+typeof(result)+', with value:'+result);
}

function sendObject(obj) {

	if (!ws_connected) {
		create();
		// call our sendObject function again after a 500 msec timeout
		var firstTrans = setTimeout(function(){
			sendObject(obj);
		}, 500);
	}
	if (ws_connected) {
		ws.send($.toJSON(obj));
	}
}
$(document).ready(function(){
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
			// send the trans action if this row is clicked
			var tid = $(this).attr('trans_id');
			var obj = {
				command: 'send_trans',
				trans_id: tid
			}

			sendObject(obj);
		})
	})
});
