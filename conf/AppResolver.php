<?php
class MyAppResolver extends AppResolver {

	public $defaultApp = 'Example';
	public $appDir;
	public $appPreload = array(); // [appName1 => numberOfInstances1], ...
	public $appPreloadPrivileged = array();
	public $htdocs_root = '/var/www/';
	public $default_document = 'index.html';

	/**
	 * @method getRequestRoute
	 * @param object Request.
	 * @param object AppInstance of Upstream.
	 * @description Routes incoming request to related application. Method is for overloading.
	 * @return string Application's name.
	 */
	public function getRequestRoute($req, $upstream) {
		if (preg_match('~^/(WebSocketOverCOMET|Example|Example.*|Monoloop|FirstData|Vendor)/~', $req->attrs->server['DOCUMENT_URI'], $m)) {
			return $m[1];
		}
 
		$host = preg_replace('~^www\.~','',basename($req->attrs->server['HTTP_HOST']));
		$req->attrs->server['GEOIP_COUNTRY_CODE'] = 'RU';
		

		preg_match('~^/(.*)$~', $req->attrs->server['DOCUMENT_URI'], $m);
		$rpath = urldecode($m[1]);
		if (strlen($rpath) < 1) {
			$rpath .= $this->default_document;
		}
		$rpath = str_replace('../','/',$rpath);
		$rpath = str_replace('/..','/',$rpath);
			
		$path = $this->htdocs_root.$host.'/'.$rpath;
		//$path = '/opt/phpdaemon/'.$rpath;
			
		if ($host === 'wakephp.ipq.co')	{
			$path = '/home/web/WakePHP/static/'.$rpath;
		  if ($rpath == '' || !file_exists($path)) {
				return 'WakePHP';
			}
		}
		elseif ($host === 'hagent.phpdaemon.net')	{
			$path = '/home/web/HAgent/static/'.$rpath;
		  if ($rpath == '' || !file_exists($path)) {
				return 'HAgent';
			}
		}
		elseif (!is_dir('/home/web/domains/' .$host)) {$host = 'loopback.su';}
		
		$req->attrs->server['FR_PATH'] = $path;			
		$req->attrs->server['FR_URL'] = 'file://'.$path;			
		$req->attrs->server['FR_AUTOINDEX'] = TRUE;
		return 'FileReader';
	}
}
return new MyAppResolver;
