<?php

/**
 * WBGym
 * 
 * Copyright (C) 2015 Webteam Weinberg-Gymnasium Kleinmachnow
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

class ModuleTimetable extends \Module
{
protected $strTemplate = 'wu_timetable';

	public function generate()
	{
		if (TL_MODE == 'BE')
		{
			$objTemplate = new \BackendTemplate('be_wildcard');

			$objTemplate->wildcard = '### WBGym WebUntis-Stundenplan ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}
		
		return parent::generate();
		
	}

	protected function compile(){
		
		//Timetables can be requested by type via WebUntis API, every type is represented by specific id defined by WU
		// -> MOVE TO CONFIG FILE
		$arrTTTypes = array(
			'class' => 1,
			'teacher' => 2,
			'subject' => 3,
			'room' => 4,
			'student' => 5
		);
		
		/*=======================*/
		
		if(!FE_USER_LOGGED_IN){
			$objHandler = new $GLOBALS['TL_PTY']['error_403']();
			$objHandler->generate($objPage->id);
		}
		$this->import('FrontendUser','User');
		
		$objUntis = new WUClient();
		
		//$data['Teachers'] = $objUntis->request('getTeachers')->result;
		//$data['Timegrid'] = $objUntis->request('getTimegridUntis');
		//$data['Classes'] = $objUntis->request('getKlassen')->result;
		//$data['Subjects'] = $objUntis->request('getSubjects')->result;
		//$data['Departments'] = $objUntis->request('getDepartments')->result;
		
		//convert data lists to array
		/*foreach($data as $i1 => $list) {
			$var = 'arr' . $i1;
			foreach($list as $elem) {
				${$var}[$elem->id] = (array) $elem;
			}
			//outputs multiple arrays, e.g. $arrStudents, $arrTeachers, ..
		}*/
		
		/*if($this->User->student == 1) {
			$tttype = $arrTTTypes['student'];
			$uid = array_search($strStudent,array_column($arrStudents,'name','id'));
		}
		if($this->User->teacher == 1) {
			$tttype = $arrTTTypes['teacher'];
			$uid = array_search($strTeacher,array_column($arrTeachers,'name','id'));
		}*/
		
		//TESTPERSON
		$arrPerson = array('sn' => 'Frau Thiele','fn'=>'Birgit','type'=>'teacher','acronym'=>'Thib');
		$arrPerson['id'] = $objUntis->request('getPersonId', array('type' => $arrTTTypes[$arrPerson['type']], 'fn'=>$arrPerson['fn'], 'sn'=>$arrPerson['sn'],'dob'=>0))->result;
		
		$this->Template->person = $arrPerson;
		
		//DEBUG
		//$this->Template->students = $arrStudents;
		//$this->Template->teachers = $arrTeachers;
		//$this->Template->studInfo = $arrStudents[$uid];
		//$this->Template->classes = $arrClasses;
		//$this->Template->departments = $arrDepartments;
		
		try {
			$this->Template->timetable = $objUntis->request('getTimetable',array('id' => $arrPerson['id'], 'type' => 2, 'startDate' => '20161101','endDate'=>'20161102'));
		}
		catch(\Exception $e) {
			$this->Template->timetable = $e->getMessage();
		}
		//$this->Template->timetable = $arrTimetable;
		
		return true;
	}
}