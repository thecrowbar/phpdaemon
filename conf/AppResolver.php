<?php

/**
 * Default application resolver
 *
 * @package Core
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class MyAppResolver extends AppResolver {

	public $defaultApp = 'Vendor';
	public $htdocs_root = '/opt/phpdaemon/static/';
	
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

		if (preg_match('~^/(WebSocketOverCOMET|Example|Vendor)/~', $req->attrs->server['DOCUMENT_URI'], $m)) {
			return $m[1];
		}

		// this is for all other requests, route to static content
		preg_match('~^/(.*)$~', $req->attrs->server['DOCUMENT_URI'], $m);
		$rpath = urldecode($m[1]);
		// strip out any .. directory traversals from the path
		$rpath = str_replace('../','/',$rpath);
		$rpath = str_replace('/..','/',$rpath);

		// add our document root to the path
		$path = $this->htdocs_root.'/'.$rpath;

		// add settings for the FileReader to find the correct file
		$req->attrs->server['FR_PATH'] = $path;
		$req->attrs->server['FR_URL'] = 'file://'.$path;
		$req->attrs->server['FR_AUTOINDEX'] = TRUE;
	
		return 'FileReader';
  
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
