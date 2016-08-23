<?php 
/*	
	Project Name: PSCRUD - Php SQLite CRUD
	Author: Joey Albert Abano
	Open Source Resource: GITHub

	The MIT License (MIT)

	Copyright (c) 2016 Joey Albert Abano		

	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	THE SOFTWARE.

	-----------------------------------------------------------------------------------------------------------------------------------------------------

	Simple single file CRUD implementation for PHP and SQLite3. Although this implemention is in a single file, it contains multiple Classes which can be 
	placed in separate files with thier own individual class names. I just placed placed them in one file on my own lazyness / stuborness! Enjoy! This
	implementation contains basic features for prototyping and testing purposes, not intended to be added for production releases. The code contain four 
	sections: Controller, View, Data Access and Helpers. Not much comments were added since the codes should be straight forward.

	Features:
	- Create, Read, Update and Delete table rows.
	- Paginated and sortable.
	- Bootstrap layout compatible.
	- Standalone and third party jquery/bootstrap compatible.
	- Searchable indexed column.	
	- Prevents double post submission.
	- Prevents sql injection.
	- Coded with MVC in mind.

	History:
	August 01, 2016 : Initial draft

	Pending Features:	
	1. Add the column order
	2. Add date picker
	3. Add javascript validators

*/

//	-----------------------------------------------------------------------------------------------------------------------------------------------------
//  Configuration
//  Note: Modify this section to define your database, manage FLAG features and define system values
//

define("DATABASE","sample.db"); // Defines the database path or connection string
define("DATABASE_LIST","sample.db,another.db"); // Not yet implemented. Handles multiple database path

define("FLAG_ENABLE_THIRDPARTY_JS_DEPENDENCY",TRUE); // Set to TRUE to load jquery, bootstrap formated implementation
define("FLAG_HIDE_PK_ALL_COLUMN",FALSE); // Set to TRUE if you want to hide primary key column
define("FLAG_TABLE_MANAGEMENT",FALSE); // Not yet implemented. Allows user to create and drop tables

define("TABLE_LIMIT",10);


/*	-----------------------------------------------------------------------------------------------------------------------------------------------------
	Initialization
 */  

session_start();

$StringHelper = new StringHelper();

$ValidatorHelper = new ValidatorHelper();

$HttpRequestHelper = new HttpRequestHelper();

$Dao = new Dao();

$Controller = new Controller();

$View = new View();

/*	-----------------------------------------------------------------------------------------------------------------------------------------------------
	Class and Function Declarations
	- Class::SQLite3Dao <extends> SQLite3
	- Class::Dao <extends> SQLite3
	- Class::Controller
	- Class::View
 */

/**	
	@Class: SQLite3Dao <extends> SQLite3
	@Description: Perform sqlite3 data access
	@Methods: 
 */
class SQLite3Dao extends SQLite3 {
	
	public $db = NULL;	
	protected $tableselected = NULL;
	protected $tablelist = NULL;
	private $isopen = FALSE;

	function __construct() {
		$this->enableExceptions(true);
	}

	// i. open database connection
	protected function opendb() {	
		if( $this->isopen !== TRUE ) {
			$this->open( $this->db );			
		}
		$this->isopen = TRUE;			
	}

	// ii. close database connection
	protected function closedb() {
		if( $this->isopen === TRUE ) {
			parent::close();	
		}
		$this->isopen = FALSE;
	}

	// 1. List all the database tables
	public function listTables() {
		
		if( $this->tablelist!==NULL ) { return $this->tablelist; }

		$this->opendb();
		$this->tablelist = array();
	    
	    $tablesquery = $this->query("SELECT name FROM sqlite_master WHERE type='table'");  
	    while ($table = $tablesquery->fetchArray(SQLITE3_ASSOC)) {
	    	if($table['name']=='sqlite_sequence') { continue; } 
	        $table['getColumnInfo'] = $this->getColumnInfo($table['name']);
			array_push($this->tablelist, $table);
	    }

	    return $this->tablelist;
	}

	// 2. Get the auto-incremented sequence number
	function getSequence($tablename=NULL) {

		$tablename = $tablename===NULL ? $this->tableselected : $tablename;

		$this->opendb();
		
		$tablesquery = $this->query( "SELECT name,seq FROM sqlite_sequence WHERE name='".$tablename."'" );
		while ($table = $tablesquery->fetchArray(SQLITE3_ASSOC)) {
			return $table;
		}

		return NULL;
	}

	// 3. Get the list of indexed columns
	function getIndexed($tablename=NULL) {

		$tablename = $tablename===NULL ? $this->tableselected : $tablename;

		$this->opendb();
		$indexarray =array();

		$indexquery = $this->query("SELECT * FROM SQLite_master WHERE type = 'index' and tbl_name = '$tablename' and sql is not null");
		while ($row = $indexquery->fetchArray(SQLITE3_ASSOC)) { 
			$str = $row['sql'] ;
			$indexarray = array_merge($indexarray, explode(',', trim(substr($str, strpos($str, '(')+1 ),')') ) );		
		}	

		return $indexarray;
	}

	// 4. Get the column information
	function getColumnInfo($tablename=NULL) {

		$tablename = $tablename===NULL ? $this->tableselected : $tablename;

		$this->opendb();
		$column_array = array();
		$seq = $this->getSequence($tablename,TRUE);

		$columnquery = $this->query("PRAGMA table_info(".$tablename.");");
		while ($column = $columnquery->fetchArray(SQLITE3_ASSOC)) { 
			$column['seq'] = ($column['pk']===1 && $seq!==NULL) ? $seq['seq'] : -1;
			array_push($column_array, $column); 
		}

		return $column_array;
	}

	// 5. List table row details
	function listq($tablename, $where=array(), $page=1) {

		$tablename = $tablename===NULL ? $this->tableselected : $tablename;

		$this->opendb();
		$resultdetail = array();
		$resultlist = array();
		$sql_condition = "";		
		$sql_limit_offset = " LIMIT " . TABLE_LIMIT . " OFFSET " . ( ($page - 1) * TABLE_LIMIT );
		
		if( $where!==FALSE && count($where)>0 ) {
			foreach ($where as $column=>$value) {
				$sql_condition = $sql_condition . $column . ' LIKE :' . $column . ' AND ';
			}
			$sql_condition = ' WHERE ' . trim($sql_condition,' AND ');
		}

		$stmt1 = $this->prepare('SELECT * FROM ' . $tablename . $sql_condition . $sql_limit_offset);
		$stmt2 = $this->prepare( 'SELECT COUNT(*) AS COUNT FROM ' . $tablename . $sql_condition );

		if( $where!==FALSE ) {
			foreach ($where as $column=>$value) {
				$stmt1->bindValue(':'.$column, '%'.$value.'%', StringHelper::getbindtype($value) );
				$stmt2->bindValue(':'.$column, '%'.$value.'%', StringHelper::getbindtype($value) );
			}	
		}

		$result1 = $stmt1->execute();				
		while ($row = $result1->fetchArray(SQLITE3_ASSOC)) { array_push($resultlist , $row); }
		$resultdetail['list'] = $resultlist;
					
		$result2 = $stmt2->execute();
		while ($row = $result2->fetchArray(SQLITE3_ASSOC)) { $result_array = $row; break; }
		$resultdetail['size'] = $result_array['COUNT'];

		$this->closedb();

		return $resultdetail;
	}    

	// 6. Get table row detail
	function get($pkvalue,$pkcolumn,$pktype) {
		return $this->getOrRemove('SELECT *',$pkvalue,$pkcolumn,$pktype);
	}

	// 7. Remove table row
	function remove($pkvalue,$pkcolumn,$pktype) {
		return $this->getOrRemove('DELETE',$pkvalue,$pkcolumn,$pktype);		
	}

	// 8. Get or Remove a table row
	function getOrRemove($sql,$pkvalue,$pkcolumn,$pktype) {

		$this->opendb();
		$ret = FALSE;		
		
		$stmt = $this->prepare($sql.' FROM '.$this->tableselected . ' WHERE ' . $pkcolumn . '=:VALUE');
		$stmt->bindValue(':VALUE', $pkvalue, StringHelper::getbindtype($pkvalue,$pktype));
		$result = $stmt->execute();
		while($row = $result->fetchArray(SQLITE3_ASSOC) ) { $ret=$row; break; }

		$this->closedb();

		return $ret;		
	}

	// 9. Update table row
	function update($array,$pkvalue,$pkcolumn,$pktype) {

		$this->opendb();
		$columnbinds[] = "";

		foreach ($array as $column=>$value) {	
			$columnbinds[] = $column;
			switch (trim($value)) {
				case 'CURRENT_TIMESTAMP': $columnbinds[] = "=DATETIME(CURRENT_TIMESTAMP, 'LOCALTIME'),"; break;						
				default: $columnbinds[] = "=:" . $column . ",";	break;
			}
		}

		$sql = 'UPDATE ' . $this->tableselected . ' SET ' . trim( implode($columnbinds),",") . ' WHERE  ' . $pkcolumn . ' = :VALUE';
		$stmt = $this->prepare($sql);
		foreach ($array as $column=>$value) { $stmt->bindValue(':'.$column, $value, StringHelper::getbindtype($value)); }
		$stmt->bindValue(':VALUE', $pkvalue, StringHelper::getbindtype($pkvalue));
		$result = $stmt->execute();

		$this->closedb();
	}

	// 10. Insert table row
	function insert($array) {

		$this->opendb();
		$columns = ""; $columnbinds = "";

		foreach ($array as $column=>$value) {			
			switch ( $value ) {
				case 'CURRENT_TIMESTAMP': $columns = $columns . $column . ","; $columnbinds = $columnbinds . "DATETIME(CURRENT_TIMESTAMP, 'LOCALTIME'),"; break;
				case 'AUTO_INCREMENT' : break;
				default: $columns = $columns . $column . ","; $columnbinds = $columnbinds . ":" . $column . ","; break;					
			}
		}
		$columns = trim($columns,","); $columnbinds = trim($columnbinds,",");

		$sql = 'INSERT INTO '.$this->tableselected.' (' . $columns . ') VALUES (' . $columnbinds . ');';	
		$stmt = $this->prepare($sql);
		foreach ($array as $column=>$value) { $stmt->bindValue(':'.$column, $value, StringHelper::getbindtype($value)); }
		$result = $stmt->execute();

		$this->closedb();
	}

}

/**	
	@Class: Dao <extend> SQLite3Dao
	@Description: 
	@Methods: 
	@Note: Modify <extends> to connect to a different database
 */
class Dao extends SQLite3Dao {

	public $tableselected = FALSE;
	public $db = FALSE;

	function __construct() {
		$this->db = DATABASE;
	}

}


/**	
	@Class: Controller
	@Description: 
	@Methods: 
	@Uses: Class::StringHelper , Class::ValidatorHelper , Class::HttpRequestHelper , Class::Dao
	@Note: 
 */
class Controller {

	public $actionkey = FALSE;
	public $helper = FALSE;
	public $dao = FALSE;
	
	/*
		@method __construct
		@description verify controller sessions and global var
	 */
	function __construct() {		
		
		global $StringHelper;
		global $ValidatorHelper;
		global $HttpRequestHelper;
		global $Dao;
		global $View;		

		// define helpers
		$this->helper = (object) array('validator' => &$ValidatorHelper, 'string' => &$StringHelper, 'http' => &$HttpRequestHelper);	

		// define dao
		$this->dao = &$Dao;		

		// store action value
		$this->actionkey = $this->helper->http->getaction();

		// reset session if db changed or is not defined in the session
		if( !isset($_SESSION['db']) || (isset($_SESSION['db']) && $_SESSION['db']!==$this->dao->db) ) {
			session_destroy(); session_start();
			$this->dao->db = DATABASE;
			$_SESSION['db'] = DATABASE;
		}	

		// check if selected table is in the session
		if( isset($_SESSION['tbl']) ) {			
			$this->dao->tableselected = $this->helper->http->getsession('tbl');
		}

		// performed required action
		$this->actionManager();
	
	}

	// 1. Action forwarder
	public function actionManager() {				
		
		if( $this->actionkey===NULL ) { return FALSE; } 

		switch ( TRUE ) {
			case StringHelper::beginwith($this->actionkey,"list-" ) :
				$this->actionList();
				break;
			case StringHelper::beginwith($this->actionkey,"search-add" ) :
				$this->actionSearchAdd();
				break;
			case StringHelper::beginwith($this->actionkey,"search-clear" ) :
				$this->actionSearchClear();
				break;
			case StringHelper::beginwith($this->actionkey,"save" ) :
				$this->actionSave();
				break;
			case StringHelper::beginwith($this->actionkey,"remove-" ) :
				$this->actionRemove();
				break;
			default: 
				break;
		}
	}

	// 2. Action, list selected table
	protected function actionList() {
		// get selected table from action key
		$this->dao->tableselected = $this->helper->validator->validateFromList(
			$this->dao->listTables(), "name", substr($this->actionkey,5) );
		// store selected table
		$_SESSION['tbl'] = $this->dao->tableselected;
		$this->helper->http->unsetsession('search');
		$this->helper->http->refreshexit();
	}

	// 3. Action, remove selected row
	protected function actionRemove() {
		try {
			$this->dao->remove($_SESSION['pk-'.substr($this->actionkey,7)], $_SESSION['pkname'], $_SESSION['pktype'], 'DELETE');
		}
		catch(Exception $e) {
			$this->helper->http->refreshwithmsg( 'Failed to remove data. ' . $e->getMessage() );
		}
		$this->helper->http->refreshwithmsg( 'Successfully removed entry.' );
	}

	// 4. Action, insert or update selected row
	protected function actionSave() {				
		$savearray = array();		
		$msg = 'Successfully saved entry.';
		
		// get form fields to be inserted or saved
		$columns = $this->dao->getColumnInfo();  
		foreach ($columns as $column=>$value) {	
			$name = '_'.$value['name']; $name_null_flag = $name.'-null';
			if( $this->helper->http->getpost($name)!==NULL ) {				
				$savearray[$value['name']] = $this->helper->http->getpost($name_null_flag)=='SETNULL' ? NULL : $this->helper->http->getpost($name);
			}
		}

		try {
			$af =  $this->helper->http->getpost('action-flow');
			// ensure action flow is defined
			if( $af===NULL ) { throw new Exception("Invalid flow, error processing request."); }			
			// perform insert or update			
			switch ( TRUE ) {
				case $this->helper->string->beginwith($af,'create'): 
					$this->dao->insert($savearray); break;
				case $this->helper->string->beginwith($af,'modify-'): 
					$this->dao->update($savearray, $_SESSION['pk-'.substr($af,7)], $_SESSION['pkname'], $_SESSION['pktype']); break;
				default:
					throw new Exception("Invalid flow, error processing request." . $af.' ---- ' . substr($af,7));					
			}		
		}
		catch(Exception $e) {
			$msg = 'Failed to save data. ' . $e->getMessage();
		}
		
		// assign message
		$this->helper->http->refreshwithmsg( $msg );
	}

	// 5. Action, clear all search parameters
	protected function actionSearchClear() {
		$this->helper->http->unsetsession('search');
		$this->helper->http->refreshexit();
	}

	// 6. Action, add search parameter
	protected function actionSearchAdd() {
		$columns = $this->dao->getColumnInfo();
		foreach ($columns as $column=>$value) {
			if($value['name']==$this->helper->http->getpost('search-column')) {
				if( isset($_SESSION['search']) ) { 
					$_SESSION['search'] = array_merge($_SESSION['search'],array($value['name']=>$_POST['search-value']));
				}
				else {
					$_SESSION['search'] = array($value['name']=>$_POST['search-value']);	
				}				
				break;
			}
		}			
		$this->helper->http->refreshexit();
	}
}


/**	
	@Class: View
	@Description: 
	@Methods: 
	@Note: 
 */
class View {

	public $helper = FALSE;
	public $dao = FALSE;
	public $controller = FALSE;

	function __construct() {		

		global $Controller; 

		$this->controller = &$Controller;

		$this->helper = &$this->controller->helper;

		$this->dao = &$this->controller->dao;
	
		$this->render();
	}
		
	// 1. Render the html
	public function render() {

		$actionkey = $this->controller->actionkey;

		$tpl = Html::$HTML_TEMPLATE;
		$tpl = str_replace("[HTML_HEADER]", ( FLAG_ENABLE_THIRDPARTY_JS_DEPENDENCY ) ? 
			Html::$HTML_HEADER_THIRDPARTY . Html::$HTML_DATETIMEPICKER : 
			Html::$HTML_HEADER_NON_THIRDPARTY, $tpl);

		$tpl = str_replace("[HTML_FORM_URL]", "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'], $tpl);

		$tpl = str_replace("[HTML_TABLE_LIST]", $this->html_tablelist(), $tpl);
		
		$tpl = str_replace("[HTML_CREATE_BTN]", ( $actionkey!=='create' && !$this->helper->string->beginwith($actionkey,'modify') ) ? $this->html_createbtn() : "" , $tpl);

		$tpl = str_replace("[HTML_MESSAGE]", $this->helper->http->getsession('msg')!==FALSE ?  $this->html_message() : "" , $tpl);

		if( $this->helper->http->getsession('tbl') ) {			
			if ( $this->helper->string->beginwith($actionkey,'create') ||  $this->helper->string->beginwith($actionkey,'modify') ) {
				$tpl = str_replace("[HTML_AREA_FORM]", $this->html_formdetails(), $tpl);			
			}
			else {
				$tpl = str_replace("[HTML_AREA_FORM]", $this->html_tabledetails().'<hr/>'.$this->html_search(), $tpl);
			}
		}
		else {
			$tpl = str_replace("[HTML_AREA_FORM]", "", $tpl);
		}

		echo preg_replace(array('/\s{2,}/','/[\t\n]/'),' ',$tpl);
	}

	// 2. HTML, Message alert
	protected function html_message() {
		return '<br/><div class="row alert alert-success">' . $this->helper->http->getsession('msg',TRUE) . '</div>';
	}

	// 3. HTML, Create button
	protected function html_createbtn() {
		return "<button name='action' type='submit' value='create' class='btn btn-primary col-xs-2'> Create Entry </button>";
	}
	
	// 4. HTML, Dropdown table list
	protected function html_tablelist() {	
		$tablelist = $this->dao->listTables(); 
		
		$element[] = '<option selected disabled>Please select</option>';		
		foreach ($tablelist as $tablenum => $table) { 
			$selected = ($this->dao->tableselected===$table['name']) ? "selected" : "";	
			$element[] = '<option value="list-' . $table['name'] . '" ' . $selected . '>' . $table['name'] . '</option>';	
		}

		return $this->html_selects(array("name"=>"action","attr"=>"onchange=\"submit()\""), implode($element) );
	}

	// 5. HTML, Table row list
	protected function html_tabledetails() {		
		$tempsessions = array();
		$currentpage = isset($_GET['o']) ? $_GET['o'] : 0;
		
		$tableselected = $this->dao->tableselected;
		$contentdetails = $this->dao->listq($tableselected, HttpRequestHelper::getsession('search'), $currentpage+1);	
		$columns = $this->dao->getColumnInfo($tableselected);

		$contentlist = $contentdetails['list'];
		$contentsize = $contentdetails['size'];

		$thead[] = '<thead><tr class="warning">';
		foreach ($columns as $columndetail => $value) {
			if( $value['pk'] === 1 ) { $tempsessions['pkname'] = $value['name']; $tempsessions['pktype'] = $value['type']; }
			if( FLAG_HIDE_PK_ALL_COLUMN===FALSE || $value['pk'] !== 1 ) { $thead[] = "<th>" . substr($value['name'], 0, 100) . "</th>";  }
		}; 
		$thead[] = '<th class="col-action">action</th></tr></thead>'; 

		$tbody[] = '<tbody>';
		if ( count($contentlist)>0 ) {
			HttpRequestHelper::unsetsession('pk-',TRUE);
			foreach ($contentlist as $rownum => $row) { 
				$tbody[] = "<tr>"; $columnindex = 0; $pkkey = FALSE;
				foreach ($row as $columnname => $cell) { 
					if( $columns[$columnindex]['pk'] == 1 ) { 
						$pkkey = uniqid('-',TRUE); $tempsessions['pk'.$pkkey] = $cell;	
					}			
					if( FLAG_HIDE_PK_ALL_COLUMN===FALSE || $columns[$columnindex]['pk'] !== 1 ) { 
						$tbody[] =  "<td>" . ( strlen((string)$cell)>100 ? substr((string)$cell,0,100)."..." : (string)$cell) . "</td>";	
					}
					$columnindex++;	
				}; 

				$tbody[] = "<td>";
				if( $pkkey !== FALSE ) { 
					$tbody[] = "<button name='action' type='submit' value='modify" . $pkkey . "' class='btn btn-primary'>Modify</button>" . "&nbsp;"; 
					$tbody[] = "<button name='action' type='submit' value='remove" . $pkkey . "' class='btn btn-default'>Remove</button>" ;
				}		
				else {
					$tbody[] = "No primary key defined.";
				}	
				$tbody[] = "</td></tr>";
			} 			
		}
		else {
			$colspan = count($columns) + 1;
			if( FLAG_HIDE_PK_ALL_COLUMN===TRUE ) { $colspan--; }
			$tbody[] =  "<tr><td colspan='".$colspan."'>Table is currently empty.</td></tr>"; 
		}
		$tbody[] = '</tbody>';

		$table_html = '<table class="table table-hover table-condensed">'. implode($thead) . implode($tbody) . "</table>";

		$pagination_html = $this->html_pagination($contentsize, $currentpage, TABLE_LIMIT, "?o=");

		HttpRequestHelper::addsession($tempsessions);

		return '<div class="container">' . $pagination_html . $table_html . $pagination_html . '</div>';
	}
	
	// 6. HTML, Generate pagination
	protected function html_pagination($total, $page, $shown, $url) {
		$pages = ceil( $total / $shown ) == 0 ? 1 :  ceil( $total / $shown ); 
		$range_start = ( ($page >= 5) ? ($page - 3) : 1 );
		$range_end = ( (($page + 5) > $pages ) ? $pages : ($page + 5) );

		$r[] = "<li class='text'><a>Page ".($page+1)." of $pages. Total entry of $total.</a></li>";

		if ( $page >= 1 ) {
			$r[] = '<li><a href="'. $url .'">&laquo; first</a></li>';
			$r[] = '<li><a href="'. $url . ( $page - 1 ) .'">&lsaquo; previous</a></li>';
			$r[] = ( ($range_start > 1) ? ' ... ' : '' ); 
		}

		if ( $range_end > 1 ) {
			foreach(range($range_start, $range_end) as $key => $value) {
				if ( $value == ($page + 1) ) $r[] = '<li class="active"><a>'. $value .'</a></li>'; 
				else $r[] = '<li><a href="'. $url . ($value - 1) .'">'. $value .'</a></li>'; 
			}
		}

		if ( ( $page + 1 ) < $pages ) {
			$r[] = ( ($range_end < $pages) ? ' ... ' : '' );
			$r[] = '<li><a href="'. $url . ( $page + 1 ) .'">next &rsaquo;</a></li>';
			$r[] = '<li><a href="'. $url . ( $pages - 1 ) .'">last &raquo;</a></li>';
		}

		return ( (isset($r)) ? '<ul class="pagination pagination-sm pull-right">'. implode($r) .'</ul>' : '');
	}

	// 7. HTML, Generate Create and Update forms
	protected function html_formdetails() {

		$resultar = NULL;
		$tableselected = $this->dao->tableselected;
		$actionkey = $this->controller->actionkey;
		$columns = $this->dao->getColumnInfo();

		$h[] = "<legend>" . strtoupper(substr($actionkey,0,6) . ' ' . $tableselected) . "</legend>";
														
		// 7.1 store action flow
		if( $this->helper->string->beginwith($actionkey, 'modify') ) {							
			$resultar = $this->dao->get($_SESSION['pk-'.substr($actionkey,7)], $_SESSION['pkname'], $_SESSION['pktype']);							
			$h[] = '<input name="action-flow" type="hidden" value="'.$actionkey.'" />';
		}
		else {
			$h[] = '<input name="action-flow" type="hidden" value="create" />';
		}	
			
		// 7.2 generate input forms			
		foreach ($columns as $columndetail => $value) {	
			if( $value['seq']>-1 ) { $value["dflt_value"] = 'AUTO_INCREMENT'; }								
			if( isset( $resultar ) ) { $value["dflt_value"] = $resultar[$value['name']]; }
								
			$f1 = $this->html_label(array("class"=>"col-sm-2","text"=>$value['name']));

			$in1 = ($value['type']=='TEXT') ? 
				$this->html_textarea( 
					array("name"=>("_".$value['name']),"placeholder"=>$value["type"],"text"=>$value["dflt_value"]) )  :
				$this->html_textinput(
					array("name"=>("_".$value['name']), 
						"class"=>( ($value['type']=='DATETIME'&&$value['dflt_value']!='CURRENT_TIMESTAMP')?' datetime ':''), "placeholder"=>$value["type"],
						"value"=>$value["dflt_value"], "readonly"=>($value['seq']>-1||$value['dflt_value']=='CURRENT_TIMESTAMP' ? 1 : NULL), 
						"attr"=>($value['notnull']===1) ? 'required ' : '') );


			$f2 = $this->html_div(array("class"=>"col-sm-5"), $in1);
				
			$in2 = ( $value['notnull']===1 ) ? "[required]" :
				'<label class="checkbox-inline">
				<input name="_'. $value['name'] . '-null" type="checkbox" placeholder="Set to Null" class="input-md" value="SETNULL" />
				[nullable]</label>';				
																	
			if ($value['pk']===1) { $in2 = $in2 . "[primary key]"; } 
																			
			$f3 = $this->html_div(array("class"=>"col-sm-5"), $in2);

			$h[] = $this->html_div(array("class"=>"form-group"), $f1 . $f2 . $f3); 
		}
								
		// 7.3 generate Save and Cancel buttons
		$s4 = $this->html_button(array("class"=>"btn-primary","value"=>"save","text"=>"Save")) . '&nbsp;' 
			. $this->html_button(array("class"=>"btn-default cancel","text"=>"Cancel"));
		$s3 = '<label class="col-md-4 control-label"></label><div class="col-md-8">' . $s4 . '</div>' ;
		$s2 = '<div class="form-group">' . $s3 . '</div>';				
		$h[] = $s2;			
																															
		return '<div class="container"><fieldset>' .  implode($h)  . '</fieldset></div>';						
	}

	// 8. HTML, Generate search form
	protected function html_search() {

		$list = $this->dao->getIndexed();

		$options = '<option value="" selected disabled>Please select</option>';
		foreach ( $list as $key => $value) { 
			$options = $options . "<option value='$value'>$value</option>"; 
		}

		$label = $this->html_label(array("class"=>"col-xs-2", "style"=>"text-align:left !important; margin-left:-12px;", "text"=>"Search Indexed Fields"));

		$searchcol = $this->html_div(array("class"=>"col-xs-3"), $this->html_selects(array("name"=>"search-column"), $options) );

		$searchcri = $this->html_div(array("class"=>"col-xs-3"), $this->html_textinput(array("name"=>"search-value","class"=>"col-xs-2","placeholder"=>"Search Criteria")) );
		 
		$searchbtn = $this->html_button(array("value"=>"search-add","text"=>"Add Search")) ."&nbsp;" . $this->html_button(array("value"=>"search-clear","text"=>"Clear Search")); 

		$searchlabel = "";
		if( isset($_SESSION['search']) ) {
			$searchlabels[] = '';
			foreach ($_SESSION['search'] as $key => $value) {	
				$searchlabels[] = '<span class="label label-success">['.$key.'] = '.$value.'</span>';
			}							
			$searchlabel = $this->html_div(array("class"=>"row"), $this->html_div(array("class"=>"col-xs-3"), implode("\r\n", $searchlabels)) );
		}	

		return $this->html_div(array("class"=>"container"), 
			$this->html_div(array("class"=>"row"), $label . $searchcol . $searchcri . $searchbtn . $searchlabel)
		);
	} 

	protected function html_div($ar=array(),$inside, $pre="", $apd="") {
		if( gettype($ar)=='string' ) { $inside = $ar; }
		$ar = array_merge(array("class"=>"","style"=>""),$ar);
		return $pre.'<div class="'.$ar["class"].'">'.$inside.'</div>'.$apd;
	}

	protected function html_label($ar=array()) {
		$ar = array_merge(array("name"=>"","value"=>"","type"=>"","class"=>"","placeholder"=>"","style"=>"","text"=>""),$ar);
		return '<label class="control-label '.$ar["class"].'" style="'.$ar["style"].'" >'.$ar["text"].'</label>';
	}

	protected function html_button($ar) {
		$ar = array_merge(array("name"=>"action","value"=>"","type"=>"submit","class"=>"btn-default","placeholder"=>"","style"=>"","text"=>""),$ar);
		return '<button name="'.$ar["name"].'" type="'.$ar["type"].'" value="'.$ar["value"].'" class="btn '.$ar["class"].'" '.$ar["style"].'>'.$ar["text"].'</button>';
	}

	protected function html_textinput($ar) {
		$ar = array_merge(array("name"=>"","value"=>"","type"=>"text","class"=>"","placeholder"=>"","style"=>"","text"=>"","disabled"=>NULL,"attr"=>""),$ar);
		return '<input name="'.$ar["name"].'" type="'.$ar["type"].'" class="form-control '.$ar["class"].'" '.$ar["style"].
			' value="'.$ar["value"].'" placeholder="'.$ar["placeholder"].'" '.($ar["disabled"]!==NULL?"disabled":"").' '.$ar["attr"].' />';
	}

	protected function html_textarea($ar) {
		$ar = array_merge(array("name"=>"","value"=>"","class"=>"","placeholder"=>"","style"=>"","text"=>""),$ar);
		return '<textarea name="'.$ar["name"].'" class="form-control '.$ar["class"].'" '.$ar["style"].'>'.$ar["text"].'</textarea>';
	}

	protected function html_selects($ar, $options=array()) {
		$ar = array_merge(array("name"=>"","value"=>"","type"=>"","class"=>"","placeholder"=>"","style"=>"","text"=>"","attr"=>""),$ar);
		$optionhtml[] =''; $content = '';
		if( gettype($options)=='array' ) {
			foreach ( $options as $key => $option) { 
				$optionhtml[] = '<option value="'.$option['value'].'" '.$option['attr'].' >'.$option['value'].'</option>'; 
			}	
			$content = implode("\r\n", $optionhtml);
		}
		if( gettype($options)=='string' ) {
			$content = $options;
		}
		
		return '<select name="'.$ar["name"].'" class="form-control '.$ar["class"].'" '.$ar["attr"].'>' . $content . '</select>';	
	}

}


/**	
	@Class: StringHelper
	@Description: 
	@Methods: 
	@Note: 
 */
class StringHelper {
	
	// 1. String manipulation check if item begins with the given strip
	public static function beginwith($string, $strip) {
		return substr($string,0, strlen($strip) )===$strip;
	}

	// 2. Identify value SQLITE3 datatype
	public static function getbindtype($value,$type=FALSE) {
		$bindtype = SQLITE3_TEXT;
		switch ( gettype($value) ) {
			case 'string': $bindtype = SQLITE3_TEXT; break;
			case 'integer': $bindtype = SQLITE3_INTEGER; break;
			case 'double': $bindtype = SQLITE3_FLOAT; break;			
			case 'NULL': $bindtype = SQLITE3_NULL; break;
			default: break;
		}
		if( $type!== FALSE ) {
			switch ( substr($type,0,3) ) {
				case 'STR': $bindtypedb = SQLITE3_TEXT; break;		
				case 'INT': $bindtypedb = SQLITE3_INTEGER; break;
				case 'FLO': $bindtypedb = SQLITE3_FLOAT; break;			
				default: break;
			}	
			$bindtype = $bindtypedb;
		}
		return $bindtype;
	}
}


/**	
	@Class: ValidatorHelper
	@Description: 
	@Methods: 
	@Note: 
 */
class ValidatorHelper {

	// 1. Validate if a $list contain an object item with a given $key name of an equal $value
	function validateFromList($list, $key, $value) {
		foreach ($list as $index => $item) {
			if( $item[$key]===$value ) return $value;
		}
		return FALSE;
	}
}


/**	
	@Class: HttpRequestHelper
	@Description: 
	@Methods: 
	@Note: 
 */
class HttpRequestHelper {

	// 1. Get POST request with a given key
	function getpost($key) {
		return isset($_POST[$key]) ? $_POST[$key] : NULL;
	}

	// 2. Get POST request with key equal to action
	function getaction() {
		return $this->getpost("action");
	}

	// 3. Session, clear a value from session
	function unsetsession($value,$isprefix=FALSE) {
		if( $isprefix ) { 
			foreach ($_SESSION as $key=>$val) {
				if( substr($key,0,strlen($value))===$value ) {					
					$var = $_SESSION[$key]; unset( $_SESSION[$key], $var );	unset( $_SESSION[$key] ); 
				}
			}
		}
		else {			
			$var = $_SESSION[$value];  unset( $_SESSION[$value], $var ); unset( $_SESSION[$value] ); 
		}	
	}

	// 4. Session, retrieve a value from session. Remove session after retrival $flush=TRUE
	function getsession($key,$flush=FALSE){
		if ( isset($_SESSION[$key] ) ) { 
			$value = $_SESSION[$key];
			if( $flush === TRUE ) {
				unset( $_SESSION[$key] );
			}
			return $value; 
		} 
		return FALSE;
	}

	// 5. Session, add a session value
	function addsession($key,$value=NULL) {
		if( gettype($key)=='array' ) {
			foreach ($key as $ref => $value) { 
				$_SESSION[$ref] = $value;
			}	
		}
		else if( gettype($key)=='string' && $value!==NULL ) {
			$_SESSION[$key] = $value;
		}
		
	}

	// 6. Refresh page, while maintaining a session message
	function refreshwithmsg($msg=NULL) {
		if( $msg !== NULL ) { $_SESSION['msg'] = $msg; }
		HttpRequestHelper::refreshexit();
	}	

	// 7. Refresh page with exit
	function refreshexit() {	
		header('Location: http://'. $_SERVER['HTTP_HOST']. explode('?', $_SERVER['REQUEST_URI'], 2) [0] , TRUE );
		die;
	}
}


/**	
	@Class: HttpRequestHelper
	@Description: Contains the static html text
	@Methods: 
 */
class Html {

function __construct() {

}

public static $HTML_DATETIMEPICKER = <<<EOF
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.37/css/bootstrap-datetimepicker-standalone.min.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.37/css/bootstrap-datetimepicker.min.css">	
	<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.10.3/moment.js"></script>	
	<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.37/js/bootstrap-datetimepicker.min.js"></script>	
	<script> $( document ).ready(function(){ $("input.datetime").datetimepicker({format:"YYYY-MM-DD HH:mm:ss"}); }); </script>
EOF;

public static $HTML_HEADER_THIRDPARTY = <<<EOF
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.6.3/css/font-awesome.min.css">
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootswatch/3.3.7/simplex/bootstrap.min.css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.4/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>		
<script> 
$( document ).ready(function(){ 
	if( $("input.datetime") && $("input.datetime").datetimepicker ) { $("input.datetime").datetimepicker({format:"YYYY-MM-DD HH:mm:ss"}); }	
	if( $("button.cancel") ) { $("button.cancel").on('click',function(e){ $('input').removeAttr('required'); }); }	
}); 
</script>

<style type="text/css">			
	th.col-action { width: 170px; }
	th { padding:16px 4px 16px 12px !important; }
	td { padding:4px 4px 4px 12px !important;   }
	ul.pagination li.text a { background:none; border:none; color: #111; font-weight:bold; }
</style>
EOF;

public static $HTML_HEADER_NON_THIRDPARTY = <<<EOF
<style type="text/css">
	ul.pagination { padding:0px; margin:0px; }
	ul.pagination li { display:inline-block; padding:0px; margin:0px; }
	.alert { color:#f00; font-weight:bold; }
	.form-group { margin-bottom:4px;  }
	.col-sm-2, .col-sm-5, .col-md-8 { display:inline-block;  }			
	.col-sm-2 { width:200px; text-align:right; }
	.col-md-8 { width:262px; text-align:right; }
</style>
<script type="text/javascript">
	var _ACTIVE_BUTTON = '';
	document.addEventListener('DOMContentLoaded',function(){ 
		/* 1. define the triggered button */
		var buttons = document.getElementsByTagName('button');
		for (var i=0; i<buttons.length; ++i) {
		    buttons[i].addEventListener('click',function(event){
				_ACTIVE_BUTTON = this.value;						
			});				
		};
		/* 2. onsubmit form listener */
		document.forms[0].addEventListener('submit',function(event){					
			var message = "";
			if( _ACTIVE_BUTTON.indexOf('remove-') !== -1 ) { message = "remove an entry"; }			
			if( message!="" && !confirm("Continue to "+message+"?") ) { event.preventDefault(); return false; }					
		});
	})
</script>
EOF;

public static $HTML_TEMPLATE = <<<EOF
<!DOCTYPE html>
<html>
<head>
	<title>PSCRUD : Php Sqlite Crud Implementation</title>
	[HTML_HEADER]
</head>
<body>
<br/> 
<div class="container">	
	<form action="[HTML_FORM_URL]" method="POST" class="form form-horizontal" role="form"">		
		<div class="container">			
			<div class="row">				
				<label class="control-label col-xs-1">Table List:</label>
				<div class="col-xs-4">[HTML_TABLE_LIST]</div>				
				[HTML_CREATE_BTN]					
			</div>			
			[HTML_MESSAGE]			
		</div><br/>						
		[HTML_AREA_FORM]				
	</form>
	<br>
</div>
</body>
</html>
EOF;

}
?>
