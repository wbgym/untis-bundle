<?php
declare(strict_types=1);
/**
 * WBGym
 *
 * Copyright (C) 2016 Webteam Weinberg-Gymnasium Kleinmachnow
 *
 * @package 	WGBym
 * @version 	0.3.0
 * @author 		Johannes Cram <craj.me@gmail.com>
 * @license 	http://www.gnu.org/licenses/gpl-3.0.html GPL
 */

/**
 * Namespace
 */
namespace WBGym;

use System;

class WUHelper extends System
{
	/**
	* Converts WebUntis dates (e.g. 20160910) to timestamp
	*
	* @param int $intDate
	* @return int
	*/
	static function dateToTime(int $intDate):int {
		$strDate = strval($intDate);
		if(strlen($strDate) != 8) return 0;
		$arrDate = array(
			'year' => substr($strDate,0,4),
			'month' => substr($strDate,4,2),
			'day' => substr($strDate,6,2),
		);
		return strtotime($arrDate['year'] . '-' . $arrDate['month'] . '-' . $arrDate['day']);
	}

	/**
	* Returns the School Hour from beginning and ending (e.g. 730 - 815)
	*
	* @param int $intStart
	* @param int $intEnd
	* @return string School Hour
	*/
	static function getSchoolHour(int $intStart,int $intEnd):?string {
		$arrStdBegin = $GLOBALS['TL_LANG']['wbuntis']['school_hours']['begin'];
		$arrStdEnd = $GLOBALS['TL_LANG']['wbuntis']['school_hours']['end'];

		//Get Beginning
		if($arrStdBegin[$intStart])
			$strTime = $arrStdBegin[$intStart];

		//Get Ending
		if($arrStdEnd[$intEnd] && $arrStdBegin[$intStart] != $arrStdEnd[$intEnd])
				$strTime .= ' - ' . $arrStdEnd[$intEnd];

		if($intStart < 730 && $intEnd > 1725)
			$strTime = 'Ganztägig';

		return strval($strTime);
	}

	/**
	* Returns string for substitution type by substitution array
	*
	* @param array $arrSub
	* @return string
	*/
	static function subType(array $arrSub):string {
		if($arrSub['type'] == 'add') {
			if($arrSub['su']) $category = 'class';
			else $category = 'no_class';
			return $GLOBALS['TL_LANG']['wbuntis']['sub_types'][$arrSub['type']][$category];
		}
		return $GLOBALS['TL_LANG']['wbuntis']['sub_types'][$arrSub['type']];
	}

}
