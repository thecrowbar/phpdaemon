<?php

/**
 * Default application resolver
 *
 * @package Core
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class MyAppResolver extends AppResolver {
	/**
	 * Location of static files to be served
	 * @var String
	 */
	public $htdocs = 'static';
	
	/**
	 * Routes incoming request to related application. Method is for overloading.	
	 * @param object Request.
	 * @param object AppInstance of Upstream.
	 * @return string Application's name.
	 */
	public function getRequestRoute($req, $upstream) {

		/*
			This method should return application name to handle incoming request ($req).
		*/

		if (preg_match('~^/(WebSocketOverCOMET|Example|ExampleWithMongo|RealTimeTrans|Vendor)/~', $req->attrs->server['DOCUMENT_URI'], $m)) {
			return $m[1];
		}
		
		//Daemon::log('Script CWD:'.getcwd());
		
		// check if we should server static content
		if (is_dir(getcwd().'/'.$this->htdocs)) {
			$spath = getcwd().'/'.$this->htdocs.'/';
			//Daemon::log('We have a htdocs directory:'.$spath);
			preg_match('~^/(.*)$~', $req->attrs->server['DOCUMENT_URI'], $m);
			//Daemon::log('preg_match:'.print_r($m, true));
			$req->attrs->server['FR_PATH'] = $spath.$m[1];
			$req->attrs->server['FR_AUTOINDEX'] = TRUE;
			//Daemon::log('About to return FileReader! $req->attrs->server:'.print_r($req->attrs->server, true));
			return 'FileReader';
		}
  
		/* Example
		$host = basename($req->attrs->server['HTTP_HOST']);

		if (is_dir('/home/web/domains/' . basename($host))) {
			preg_match('~^/(.*)$~', $req->attrs->server['DOCUMENT_URI'], $m);
			$req->attrs->server['FR_PATH'] = '/home/web/domains/'.$host.'/'.$m[1];
			$req->attrs->server['FR_AUTOINDEX'] = TRUE;
			return 'FileReader';
		} */
	}
	
}

return new MyAppResolver;
