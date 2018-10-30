<?php
declare(strict_types=1);
/**
 * WBGym
 *
 * Copyright (C) 2016-2017 Webteam Weinberg-Gymnasium Kleinmachnow
 *
 * @package 	WGBym
 * @version 	0.4.0
 * @author 		Johannes Cram <craj.me@gmail.com>
 * @author 		Markus Mielimonka <mmi.github@t-online.de>
 * @license 	http://www.gnu.org/licenses/gpl-3.0.html GPL
 */

/**
 * Namespace
 */
namespace WBGym;

use BackendTemplate;
use Exception;
use FrontendUser;
use Input;
use Module;
use stdClass;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use System;

class ModuleSubstitutions extends Module
{
	protected $strTemplate = 'wu_substitutions';

	protected $arrSubs = [];

	protected $arrDateStr = [];
	protected $arrSubsRaw=[];

	/**
	 * the substitutions model
	 * @var Substitutions
	 */
	protected $substitutions;


	public function generate()
	{
		if (TL_MODE == 'BE')
		{
			$objTemplate = new BackendTemplate('be_wildcard');

			$objTemplate->wildcard = '### WBGym WebUntis-Vertretungen ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		return parent::generate();

	}

	protected function compile()
	{

		//Check permissions and initialize
		if (!FE_USER_LOGGED_IN) {
			$objHandler = new $GLOBALS['TL_PTY']['error_403']();
			$objHandler->generate($objPage->id);
		}
		$this->import('FrontendUser','User');
		$this->loadLanguageFile('wbuntis');
		$this->substitutions = new Substitutions();

		//Determine the mode (all subs or just mine)
		if(Input::get('show') == 'all') $this->strSelector = 'all';

		//Find User Type
		$objUser = FrontendUser::getInstance();
		if($objUser->teacher) {
			$user = 'teacher';
			$buttonLabel = 'Meine Vertretungen (' . $objUser->acronym . ')';
			//set selector
			if($this->strSelector != 'all') $this->strSelector = $objUser->acronym;
		}
		elseif($objUser->student) {
			$user = 'student';

			//find course
			$onlyGrade = WBGym::formSelector() == 0;
			$course = WBGym::grade($objUser->course);
			if(!$onlyGrade) {
				$course .= '/' . WBGym::formSelector($objUser->course);
				$buttonLabel = 'Meine Klasse (' . $course . ')';
			}
			else $buttonLabel = 'Mein Jahrgang (' . $course .')';

			//set selector
			if($this->strSelector != 'all') $this->strSelector = $course;
		}
		else {
			$this->strSelector = 'all';
		}

		$this->getOrderedSubstitutions();

		if (is_null($this->Template->hint) && !$this->substitutions->isAvailable())
		$this->Template->hint = 'Der online-Vertretungsplan ist zurzeit nicht zwingend aktuell! Bitte achtet auch auf den '
		. 'in der Schule aushängenden Plan!';
		$this->Template->selector = $this->strSelector;
		$this->Template->user = $user;
		$this->Template->buttonLabel = $buttonLabel;
		//fix _locale in addToUrl()
		$this->Template->allHref = explode('?_', $this->addToUrl('show=all'))[0];
		$this->Template->mineHref = explode('?_', $this->addToUrl('show=mine'))[0];

		$this->Template->dateStr = $this->arrDateStr;
		$this->Template->subs = $this->arrSubs;
		$this->Template->update = intval(substr(strval($this->substitutions->getLastImport()),0,10));
		//WebUntis returns weird timestamps with too many digits (but if we take the first 10, the timestamp is correct)
	}
	/**
	 * gets the subs array and rearranges it into $this->arrSubs
	 * @var array $arrSubs
	 */
	protected function getOrderedSubstitutions():void
	{
		if(!$this->substitutions->loadInto($this->arrSubsRaw)) {
			$this->Template->hint = 'Fehler - '.$this->substitutions->error->getMessage().'<br />Bitte kontaktieren Sie das Webteam.';
		} else {
			$this->addStringsAndReorder();

			//Sort days
			if($this->arrSubs) {
				$this->sortByDay($this->arrSubs);
			}
			//find and merge block hours
			foreach ($this->arrSubs as $i => $day){ foreach($day as $kl => $years) { foreach($years as $cl => $classes) {
				foreach ($classes as $time => $hrs) { foreach ($hrs as $id => $sub) {
					if(is_numeric($time) && $classes[$time+1]) $classes = $this->buildBlockHour($sub, $id, $classes);
				}} $this->arrSubs[$i][$kl][$cl] = $classes;
			}}}
		}
	}
	/**
	 * sort the substitutions array by different days.
	 *
	 * @param array $subs the array to sort (by-reference)
	 */
	protected function sortByDay(array &$subs):void
	{
		krsort($subs);
		foreach($subs as $i => $day) {

			//Get Date and WBCalendar information
			$this->getDateAndInfo($i);

			//sort years
			ksort($subs[$i]);
			foreach($day as $kl => $years) {

				//sort klassen
				ksort($subs[$i][$kl]);
				foreach($years as $cl => $classes) {

					//sort classes naturally
					ksort($subs[$i][$kl][$cl], SORT_NATURAL);
					foreach($classes as $time => $hrs) {

						//sort hours naturally
						ksort($subs[$i][$kl][$cl][$time], SORT_NATURAL);
						foreach($hrs as $id => $sub) {

							//merge "cancel" with "additional" entries if possible
							$hrs = $this->mergeCancelWithSub($sub, $id, $hrs);
						}
						$subs[$i][$kl][$cl][$time] = $hrs;
					}
				}
			}
		}
	}

	/**
	* Convert Subs to array, add teacher info and reschedule string, add year and reorder Array
	*
	* @var $arrSubsRaw
	* @var $arrSubs
	*/
	protected function addStringsAndReorder():void
	{

		//Initialize new Subs Array
		$date = $this->substitutions->intStart;
		while($date <= $this->substitutions->intEnd) {
			//do not add saturdays and sundays to subs array by default
			$weekNum = date('w', strtotime($date));
			if($weekNum != 0 && $weekNum != 6) $this->arrSubs[$date] =[];
			$date = date('Ymd', strtotime($date . ' +1 day'));
		}

		foreach($this->arrSubsRaw as $sub) {
			//convert object to array
			$sub = (array) $sub;


			$sub['time'] = WUHelper::getSchoolHour($sub['startTime'],$sub['endTime']);
			$sub['type_str'] = WUHelper::subType($sub);
			if($sub['reschedule']) {
				$sub['reschedule'] = (array) $sub['reschedule'];
				$sub['reschedule']['str'] = $sub['type'] == 'shift' ? 'verlegt von ' : 'verlegt nach ';
				$sub['reschedule']['str'] .= date('d.m.Y',WUHelper::dateToTime($sub['reschedule']['date']));
			}
			// $arrTeachers = [];
			foreach($sub['te'] as $i => $te) {
				if($te->name) $sub['te'][$i]->info = WBGym::getTeacherByAcronym($te->name);
				if($te->orgname) $sub['te'][$i]->orginfo = WBGym::getTeacherByAcronym($te->orgname);
				$arrTeachers[] = $te->name;
				$arrTeachers[] = $te->orgname;
			}
			//order subtitutions
			foreach ($sub['kl'] as $kl) {
				//get grade of substitution
				//e.g. "10/4" => year = 10
				if(strlen($kl->name) == 4) {
					$year = substr($kl->name,0,2);
				}
				//e.g. "6/1" => year = 6
				elseif(strlen($kl->name) == 3) {
					$year = substr($kl->name,0,1);
				}
				//e.g. "11" => year = 11
				elseif(strlen($kl->name) == 2) {
					$year = $kl->name;
				}
				$sub['course'] = $kl->name;

				//REORDER AND FILTER SUBSTITUTIONS
				//TODO: move to seperate filter method!!
				if($this->strSelector == 'all' || in_array($this->strSelector,$arrTeachers) || $this->strSelector == $kl->name) {
					$this->arrSubs[$sub['date']][$year][$kl->name][$sub['time']][] = $sub;
				}
			}
		}
	}

	/**
	* Get Date string and WBCalendar information for the date (e.g. A-Week, Herbstferien, etc.)
	*
	* @param int $intTstamp
	* @var $arrDateStr
	*/
	protected function getDateAndInfo(int $intTstamp):void
	{
		//generate date from string
		$time = WUHelper::dateToTime($intTstamp);

		//get week info from WBCalendar
		$arrInfo = WBCalendar::getInfoForDay($time);
		if($arrInfo['type']['name'] == 'lessons') $strInfo = $arrInfo['title'] . '-Woche';
		elseif($arrInfo) $strInfo = $arrInfo['type']['str'] . ' (' . $arrInfo['title'] . ')';
		else $strInfo = '';

		//build date string
		$this->arrDateStr[$intTstamp]['date'] = $GLOBALS['TL_LANG']['wbgym']['weekday'][strtolower(date('D',$time))] . ', ' . date('d.m.Y',$time);
		$this->arrDateStr[$intTstamp]['info'] = $strInfo;
	}

	/**
	* If $arrSub lesson is of type "cancel", seek if there is a subsutition for cancellation in $arrSubs and merge entries
	*
	* @param array $arrSub
	* @param array $arrSubs All Subs at the same time in the same class
	* @param int $intId
	* @return array The new $arrSubs
	*/
	protected function mergeCancelWithSub (array $arrSub,int $intId,array $arrSubs):array
	{

		$blnDoNotMerge = false;

		foreach ($arrSub['su'] as $su) {
			//if cancel subject is longer than 3 chars, it's a "Kurs" and cannot be merged
			if(strlen(strval($su->name)) > 3 || strlen(strval($su->orgname)) > 3) $blnDoNotMerge = true;
		}

		if ($arrSub['type'] == 'cancel' && !$blnDoNotMerge) {
			foreach($arrSubs as $idNew => $claNew) {

				foreach ($claNew['su'] as $su) {
					//if additional subject is longer than 3 chars, it's a "Kurs" and cannot be merged
					if(strlen(strval($su->name)) > 3 || strlen(strval($su->orgname)) > 3) $blnDoNotMerge = true;
				}

				if(($claNew['type'] == 'add' || $claNew['type'] == 'shift') && !$blnDoNotMerge) {

					//set new type and add reschedule object if necessary
					if($claNew['type'] == 'add') {
						$arrSub['type'] = 'subst';
					}
					elseif($claNew['type'] == 'shift') {
						$arrSub['type'] = 'shift';
						$arrSub['reschedule'] = $claNew['reschedule'];
					}
					$arrSub['type_str'] = WUHelper::subType($arrSub);

					//move subjects to "orgsubject" in cancelled object
					foreach($arrSub['su'] as $suId => $subject) {
						$arrSub['su'][$suId]->orgname = $subject->name;
						unset($arrSub['su'][$suId]->name);
						unset($arrSub['su'][$suId]->id);
					}
					//move teachers to "orgteacher" in cancelled object
					foreach($arrSub['te'] as $teId => $teacher) {
						$arrSub['te'][$teId]->orgname = $teacher->name;
						$arrSub['te'][$teId]->orginfo = $teacher->info;
						unset($arrSub['te'][$teId]->name);
						unset($arrSub['te'][$teId]->info);
						unset($arrSub['te'][$teId]->id);
					}
					//move rooms to "orgroom" in cancelled subject
					foreach($arrSub['ro'] as $roId => $room) {
						$arrSub['ro'][$roId]->orgname = $room->name;
						unset($arrSub['ro'][$roId]->name);
						unset($arrSub['ro'][$roId]->id);
					}
					//add "new subjects" from "add" object to former "cancel" object
					$int = 0;
					foreach($claNew['su'] as $suId => $subject) {
						if(!isset($arrSub['su'][$int])) $arrSub['su'][$int] = new stdClass();
						$arrSub['su'][$int]->name = $subject->name;
						$int++;
					}
					//add "new teachers" from "add" object to former "cancel" object
					$int = 0;
					foreach($claNew['te'] as $teId => $teacher) {
						if(!isset($arrSub['te'][$int])) $arrSub['te'][$int] = new stdClass();
						$arrSub['te'][$int]->name = $teacher->name;
						$arrSub['te'][$int]->info = $teacher->info;
						$int++;
					}
					//add "new rooms" from "add" object to former "cancel" object
					$int = 0;
					foreach($claNew['ro'] as $roId => $room) {
						if(!isset($arrSub['ro'][$int])) $arrSub['ro'][$int] = new \stdClass();
						$arrSub['ro'][$int]->name = $room->name;
						$int++;
					}

					//handle info text
					$arrSub['txt'] = $arrSub['txt'] == $claNew['txt'] ? $arrSub['txt'] : $arrSub['txt'] . $arrSubs[$idNew]['txt'];
					unset($arrSubs[$idNew]);
				}
			}
			$arrSubs[$intId] = $arrSub;
		}
		return $arrSubs;
	}

	/**
	* Build block hour if a fitting entry is found in $arrSubs
	*
	* @param array $arrSub All Subs
	* @param int $intId
	* @param array $arrSubs All Subs within this class
	* @return array The new $arrSubs
	*/
	protected function buildBlockHour(array $arrSub,int $intId,array $arrSubs):array
	{
		$arrFirstHour = [1,3,5,7];
		foreach($arrSubs[$arrSub['time']+1] as $idNew => $claNew) {
			if(
				in_array($arrSub['time'],$arrFirstHour) &&
				(($claNew['lsid'] == $arrSub['lsid'] || $claNew['course'] < 9) && !$claNew['reschedule']) &&
				$claNew['ro'] == $arrSub['ro'] &&
				$claNew['te'] == $arrSub['te'] &&
				$claNew['su'] == $arrSub['su'] &&
				$claNew['type'] == $arrSub['type']
			) {
				if($claNew['txt'] != $arrSub['txt']) {
					$arrSubs[$arrSub['time']][$intId]['txt'] = $arrSub['txt'] . '<br />' . $claNew['txt'];
				}
				unset($arrSubs[$arrSub['time']+1][$idNew]);
				$arrSubs[$arrSub['time']][$intId]['time'] = strval($arrSub['time']) . ' - ' . strval($arrSub['time']+1);
			}
		}
		return $arrSubs;
	}
}
