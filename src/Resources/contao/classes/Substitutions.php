<?php
declare(stric_types=1);
/**
 * WBGym
 *
 * Copyright (C) 2018 Webteam Weinberg-Gymnasium Kleinmachnow
 *
 * @package 	WGBym
 * @version 	1.0.0
 * @author 		Markus Mielimonka <mmi.github@t-online.de>
 * @license 	http://www.gnu.org/licenses/gpl-3.0.html GPL
 */

/**
 * Namespace
 */
namespace WBGym;

use Exception;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use System;

/**
 * Model class for the substitutions plan
 */
class Substitutions extends System
{
	/**
	 * the last error that occured
	 *
	 * @var Exception
	 */
	public $error;
	/**
	 * the first day to load
	 * @var int
	 */
	public $intStart = 0;
	/**
	 * the last day to load
	 * @var int
	 */
	public $intEnd = 0;

	/**
	 * An associative array holding the ordered substitutions plan ready for output.
	 *
	 * @var array
	 */
	protected $arrSubs = [];
	/**
	 * Whether to load in debug mode (more days to compare)
	 *
	 * @var bool
	 */
	protected $blnDebug = false;
	/**
	 * The last import time of the webuntis substitutions plan
	 *
	 * @var int
	 */
	protected $lastImport = 0;

	/**
	 * the filesystem cache object
	 *
	 * @var FilesystemAdapter
	 */
	protected $objCache;
	/**
	 * the WUClient class instance.
	 *
	 * @var WUClient
	 */
	protected $objClient;
	/**
	 * the cache prequalifier for all stored data
	 *
	 * @var string
	 */
	protected $qualifier = 'webuntis';

	/**
	 * lifetime of a cached substitution plan
	 *
	 * @var int
	 */
	protected static $cacheTime = 24*60*60*3; # cache for three days

	public function __construct()
	{
		parent::__construct();
		# not useing the contao cache, which is cleard every day (at least in our system).
		if ($this->blnDebug) $this->qualifier .= '.debug';
		$this->objCache = new FilesystemAdapter($this->qualifier, static::$cacheTime, System::getContainer()->getParameter('kernel.project_dir').'/system/cache/wbgym');
		try {
			$this->objClient = new WUClient();
		} catch (Exception $e) {
			# webuntis connection failed.
			$this->error = $e;
		}
	}
	/**
	 * loads the current substitution plan
	 *
	 * @return bool success
	 */
	public function load():bool
	{
		$res = $this->isNewerVersionAvailable();
		if (is_null($res)) {
			$this->error = new Exception("Vertretungsplan kann zurzeit nicht geladen werden", 1, $this->error);
			return false;
		} elseif ($res === true) {
			return $this->loadForward();
		} elseif ($res === false) {
			return $this->loadFromCache();
		}
	}
	/**
	 * loads the substitution plan into reference parameter
	 * @param  array $subs       the substituitons reference
	 * @param  int   $lastImport the last import date reference
	 * @return bool              success?
	 */
	public function loadInto(array &$subs):bool
	{
		$res = $this->load();
		$subs =  (array)$this->arrSubs;
		return $res;
	}
	/**
	 * loads the substitutions plan from cache
	 * @return bool successful?
	 */
	protected function loadFromCache():bool
	{
		if (!$this->isCached()) return false;
		// allow extern date manipulations
		if ($this->intStart === 0 || $this->intEnd === 0) $this->generateDates();
		$this->arrSubs = (array)$this->objCache->getItem('substitutions')->get();
		$this->lastImport = (int)$this->objCache->getItem('lastImportTime')->get();
		$this->stripDates($this->intStart, $this->intEnd);
		return true;
	}

	public function isNewer(int $newDate):bool
	{
		return $this->lastImport < $newDate;
	}
	/**
	 * loads the substitutions plan from the web
	 * @return bool successful?
	 */
	protected function loadFromUntis():bool
	{
		if ($this->lastImport === 0) {
			try {
				$this->lastImport = (int)$this->objClient->request('getLatestImportTime')->result;
			} catch (Exception $e) {
				$this->error = $e;
				return false;
			}
		}
		# allow extern interval manipulations (only whole interval)
		if ($this->intStart === 0 || $this->intEnd === 0) $this->generateDates();
		try {
			$this->arrSubs = (array)$this->objClient->request('getSubstitutions',['startDate' => $this->intStart, 'endDate' => $this->intEnd, 'departmentId' => 0])->result;
		} catch (Exception $e) {
			$this->error = $e;
			return false;
		}
		$this->saveToCache();
		return true;
	}
	/**
	 * compares the cached version with the loaded version at webuntis.
	 * Cache is preferred.
	 *
	 * @return bool|null true if there is a new version stored at webuntis, null if none is available
	 */
	public function isNewerVersionAvailable():?bool
	{
		# arrCache is inherited from System.
		if (is_null($this->arrCache['isNewerVersionAvailable'])) {
			if (!$this->isCached() && !$this->isAvailable()) $this->arrCache['isNewerVersionAvailable'] = null;
			elseif (!$this->isCached()) $this->arrCache['isNewerVersionAvailable'] = true;
			elseif (!$this->isAvailable()) $this->arrCache['isNewerVersionAvailable'] = false;
			else {
				try {
					$this->lastImport = (int)$this->objClient->request('getLatestImportTime')->result;
				} catch (Exception $e) {
					$this->error = $e;
				}
				$lastCacheUpdate = $this->objCache->getItem('lastImportTime')->get();
				if ($lastCacheUpdate < $this->lastImport) $this->arrCache['isNewerVersionAvailable'] = true;
				else $this->arrCache['isNewerVersionAvailable'] = false;
			}
		}
		return $this->arrCache['isNewerVersionAvailable'];
	}
	/**
	 * check whether a substitution plan is cached.
	 * @return bool
	 */
	public function isCached():bool
	{
		# arrCache is inherited from System.
		if (is_null($this->arrCache['isCached'])) {
			if (!$this->objCache->getItem('lastImportTime')->isHit()) $this->arrCache['isCached'] = false;
			elseif (!$this->objCache->getItem('substitutions')->isHit()) $this->arrCache['isCached'] = false;
			else {
				$end = $this->objCache->getItem('endDate');
				if (!$end->isHit()) $this->arrCache['isCached'] = false;
				# today has not been loaded.
				elseif ($end->get() < date("Ymd")) $this->arrCache['isCached'] = false;
				else $this->arrCache['isCached'] = true;
			}
		}
		return $this->arrCache['isCached'];
	}
	/**
	 * tests whether the Webuntis-Service is available
 	 */
	public function isAvailable():bool
	{
		return !is_null($this->objClient);
	}
	/**
	 * getter for the Sub plan's array
	 * @return array the substitutions plan
	 */
	public function getSubs():array
	{
		return $this->arrSubs;
	}
	/**
	 * getter for the last import date
	 * @return int last import date
	 */
	public function getLastImport():int
	{
		return $this->lastImport;
	}
	/**
	 * automatically generate the dates to specify the load interval of the substitutions plan
	 */
	public function generateDates():void
	{
		if(!$this->blnDebug) {
			$this->intStart = date('Ymd');
			# Freitag => 3 Tage bis Montag
			if(date('w') == 5) $this->intEnd = date('Ymd', strtotime("+3 days"));
			# Samstag => 2 Tage bis Montag
			elseif(date('w') == 6) $this->intEnd = date('Ymd', strtotime('+2 days'));
			# Sonntag - Donnerstag => ein Tag bis zum nächsten Schultag
			else $this->intEnd = date('Ymd', strtotime('+1 days'));
		}
		else {
			$this->intStart = date('Ymd', strtotime("-4 days"));
			$this->intEnd = date('Ymd', strtotime("+5 days"));
		}
	}
	/**
	 * saves the loaded content to the cache, do only call after loadFromUntis succeeded.
	 */
	protected function saveToCache():void
	{
		# clear all expired items to prevent junk:
		$this->objCache->prune();
		# save the import time:
		$cacheLastUpdate = $this->objCache->getItem('lastImportTime');
		$cacheLastUpdate->set($this->lastImport);
		$this->objCache->saveDeferred($cacheLastUpdate);
		# save the cached max time
		$cacheEnd = $this->objCache->getItem('endDate');
		$cacheEnd->set($this->intEnd);
		$this->objCache->saveDeferred($cacheEnd);
		# save substitutions plan
		# NOTE: saving the raw content prevents the filter from deleteing parts of the content
		# NOTE: or all loops from beeing run twice.
		$cacheSubstitutions = $this->objCache->getItem('substitutions');
		$cacheSubstitutions->set($this->arrSubs);
		$this->objCache->saveDeferred($cacheSubstitutions);
		# save all:
		$this->objCache->commit();
	}
	/**
	 * loads the substitutions plan from webuntis some days in advance
	 *
	 * This caches a wider array of days ahead, to add to failure security due to
	 * webuntis errors.
	 * @param  integer $days the number of days to load in advance
	 * @return bool          success
	 */
	public function loadForward(int $days = 3):bool
	{
		$this->generateDates();
		$intEnd = $this->intEnd;
		$this->intEnd = date("Ymd", strtotime($this->intEnd.' +'.$days.' days'));
		$res = $this->loadFromUntis(); # already saves to cache
		# reset state:
		$this->intEnd = $intEnd;
		$this->stripDates($this->intStart, $this->intEnd);
		return $res;
	}
	/**
	 * clears the $arrSubs of all substitution outside of the given interval
	 * @param int $min min date (format Ymd)
	 * @param int $max max date (format Ymd)
	 * @var array $arrSubs
	 */
	protected function stripDates(int $min,int $max):void
	{
		foreach ($this->arrSubs as $i => $sub) {
			if ($sub->date < $min) unset($this->arrSubs[$i]);
			elseif ($sub->date > $max) unset($this->arrSubs[$i]);
		}
	}
}
