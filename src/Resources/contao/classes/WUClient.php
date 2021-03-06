<?php
declare(stric_types=1);
/**
 * WBGym
 *
 * Copyright (C) 2016-2018 Webteam Weinberg-Gymnasium Kleinmachnow
 *
 * @package     WGBym
 * @author      Johannes Cram <craj.me@gmail.com>
 * @license     http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */

/**
 * Namespace
 */
namespace WBGym;

use Exception;
use System;
use stdClass;

class WUClient extends System {

	protected static $jsessionid = null;
	protected static $METHOD = 'POST';
	protected static $CONTENT_TYPE = 'application/json';
	protected static $CONNECTION_TYPE = 'close';
	protected static $currentId;

	/**
	* {@inheritdoc}
	* Authenticate at WebUntis-API
	* @throws Exception
	*/
	public function __construct() {
		parent::__construct();
		$container = System::getContainer();

		if(!static::$jsessionid) {
			$params = array(
				'user' 		=> $container->getParameter('wbgym_untis.username'),
				'password'  => $container->getParameter('wbgym_untis.password'),
				'client'	=> $container->getParameter('wbgym_untis.client_name')
			);
			$res = $this->request('authenticate',$params);

			if($res->error) throw new Exception ('WebUntis Authentication error ' . $res->error->code . ': ' . $res->error->message);
			elseif(!$res->result) throw new Exception ('WebUntis Service not available');

			static::$jsessionid = $res->result->sessionId;
		}
	}

	/**
	* Send a JSON-RPC-request to API
	*
	* @param string $strMethod Method
	* @param array $strParams Paramters
	* @return object response of the request
	* @throws Exception on error realted webuntis-api
	*/
	public function request(string $strMethod,array $arrParams = null):stdClass {
		$container = System::getContainer();

		static::$currentId = rand();

		$strRequest = json_encode(array(
			'id'		=> static::$currentId,
			'method'	=> $strMethod,
			'params'	=> $arrParams,
			'jsonrpc' 	=> '2.0'
		));
		$opts = array(
			'http' => array(
				'method' => static::$METHOD,
				'header' =>
					"Content-Type: " . static::$CONTENT_TYPE."\r\n" .
					"Connection: " . static::$CONNECTION_TYPE."\r\n" .
					"Content-Length: " . strlen($strRequest)."\r\n",
				'content' => $strRequest
			)
		);
		if(static::$jsessionid !== null) {
			$opts['http']['header'] .= "Cookie: JSESSIONID=".static::$jsessionid."\r\n";
		}

		$context = stream_context_create($opts);
		if(static::$jsessionid !== null) {
			$uri = $container->getParameter('wbgym_untis.api_url');
		}
		else {
			$uri = $container->getParameter('wbgym_untis.api_url') . '?school=' . $container->getParameter('wbgym_untis.school_code');
		}

		$res = json_decode(file_get_contents($uri, false, $context));

		if($res->error) {
			throw new Exception('WebUntis Error ' . $res->error->code . ': ' . $res->error->message);
		}
		else if ($res->id != static::$currentId){
			throw new Exception('WebUntis Error: Request IDs do not match (Requested: '.static::$currentId.', Recieved: '.$res->id);
		}
		else {
			return $res;
		}
	}

	/**
	* Logout from the API
	* @throws Exception when $this->request throws
	*/
	public function __destruct() {
		$this->request('logout');
		static::$jsessionid = null;
	}

}
?>
