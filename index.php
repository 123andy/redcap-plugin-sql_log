<?php

/**
This is a plugin designed to retrieve the SQL for a given record from the redcap_log.  It can come in handy for 'replaying' an erroneously deleted record
**/

require_once "../../redcap_connect.php";

// Page header
include APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

// Get User Rights
$user_rights = REDCap::getUserRights(USERID);
$user_rights = $user_rights[USERID];

// Only allowed projects or superuser
if (!SUPER_USER && !$user_rights['design']) {
	displayMsg("You must have Project Setup rights to use this plugin", "error", $msgAlign="center", $msgClass="red", $msgIcon="exclamation_red.png", 0, false);
	exit();
}

// Get record if passed in via plugin - it will default the dropdown to this value
$record = isset($_GET['record']) ? $_GET['record'] : '';

// Override get record with posted one if set
$record = isset($_POST['record']) ? $_POST['record'] : '';

// Fix Inserts
$fix_inserts = (isset($_REQUEST['fix_inserts']) ? $_REQUEST['fix_inserts'] : '');

/*
*	DISPLAY DEFAULT/GET FORM TO SELECT RECORD TO ANALYZE
*/

// Instructions
print "
	<div class='chklisthdr' style='color:rgb(128,0,0);margin-top:10px;'>
		Replay Record SQL form Logs
	</div>
	<p style=''>
		This plugin allows you to obtain a log of all SQL activity for a given record.  It is useful to help recover a record that might have been deleted by replaying the original SQL up to the point of the deletion.  There are a <b>NUMBER</b> of caveats with doing this, potentially including:  The log for this record will not show all the subsequent inserts you make.  If there are ASI's I think they might re-fire but I haven't tested.  You run the risk of double-inserting entries into REDCap data if the record isn't already missing.  Survey completion flags and dates will not be recovered.  All this said, I have used it many times to help someone recover form an accidental delete.
	</p>
	<p>
		Select the record to query - it will show *MISSING* if it is no longer part of the project.
	</p>
";

// Obtain all records in the project
$records = REDCap::getData('array',NULL,REDCap::getRecordIdField());

// Obtain a list of records from the log
$sql = sprintf("select distinct(pk) from redcap_log_event where project_id = %s and event in ('INSERT','UPDATE','DELETE') and object_type='redcap_data' order by pk * 1", $project_id);
$q = db_query($sql);
$log_records = array();
$deleted_records = array();
$options = array();
while ($row = db_fetch_assoc($q)) {
	$pk = $row['pk'];
	$log_records[] = $pk;
	if (!isset($records[$pk])) {
		$deleted_records[] = $pk;
		$options[$pk] = "$pk **Missing**";
	} else {
		$options[$pk] = "$pk";
	}
}

print "
	<form method='POST'>".
		RCView::select(array('id'=>'record','name'=>'record','class'=>'x-form-text x-form-field'),$options, $record) . "
		<span style='margin-left:10px;'>
			<input type='checkbox' name='fix_inserts' value='1' " . ($fix_inserts == 1 ? 'checked' : '') . "> Fix inserts that were logged before the 'instance' column was added to redcap_data
		</span>
		<div style='padding:10px 0px;'>
			<input class='jqbutton' type='submit' name='submit' value=' Lookup Log Entries '/>
		</div>
	</form>
";




/**
 * PROCESS THE POST RECORD
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST') 
{
	if (empty($record)) {
		displayMsg("No record id was passed", "error", $msgAlign="center", $msgClass="red", $msgIcon="exclamation_red.png", 0, false);
		exit();
	}

	//print "POST: <pre>".print_r($_POST,true)."</pre>";

	// Lookup Log Info
	$sql = sprintf("select log_event_id, ts, event, sql_log, description, user from redcap_log_event where project_id = %s and event in ('INSERT','UPDATE','DELETE') and object_type='redcap_data' and pk = '%s' order by ts",
		mysql_real_escape_string($project_id),
		mysql_real_escape_string($record)
	); 
	$q = db_query($sql);
	$flex_data = array();	
	$flex_headers = array(
		'' => array(20,"<img src='" . APP_PATH_IMAGES . "report.png'>"),
		'log_event_id'	=>	array(50,'ID'),
		'ts'	=>	array(95,'Timestamp','left',"date"),
		'event'	=>	array(50,'Event'),
		'user'	=> array(50,'User'),
		'description'	=>	array(120,'Description'),
		'sql_log' => array(600, 'Sql Log')
	);

	// Figure out width for flexgrid table based on number of columns and padding
	$width = 10 * count($flex_headers) + 12;
	foreach ($flex_headers as $a) {
		$width = $width + array_shift($a);
	}

	while ($row = db_fetch_assoc($q)) {
		$log_event_id = $row['log_event_id'];
		$ts = $row['ts'];
		$event = $row['event'];
		$sql_log = $row['sql_log'];
		$user = $row['user'];
		// add right semicolon
		if (!empty($sql_log) && substr($sql_log, -1) != ';') $sql_log .= ';';
		// add carriage returns
		$sql_log = preg_replace('/;\s*(update|insert|delete)/mi',";\n$1",$sql_log);
		// Fix inserts by adding value for instance id
		if ($fix_inserts) $sql_log = fixInstanceIssue($sql_log);
		
		// Add comment header to sql_log
		$sql_header = "-- [ID:{$log_event_id}] $event by $user on " . DateTimeRC::format_ts_from_int_to_ymd($ts);
		$description = $row['description'];
		$checkbox = "<input id='$log_event_id' name='selected_rows' type='checkbox' value='$log_event_id' checked onclick='updateAggregateSql();'/>";

		// Build flexgrid data
		$flex_data[] = array(
			$checkbox,
			$log_event_id,
			DateTimeRC::format_ts_from_int_to_ymd($ts),
			$event,
			$user,
			$description,
			"<div class='sql' title='$sql_header'>".nl2br($sql_log)."</div>"
		);
	}

	// If the table is empty, then render a row to say so
	if (empty($flex_data)) $flex_data[] = explode("|", "<span style='color:red;'>No data</span>".str_repeat ("|",count($column_details)));

	// Render a Search Box
	$title = "SQL Log Summary for Record: $record";
	$grid_name = 'sql_log_results';
	$height = "auto";

	print "
		<hr>
		<div class='chklisthdr' style='color:rgb(128,0,0);margin-top:10px;'>Full SQL from Log Entries for Record $record</div>
		<p>Use the filters below to edit the entries - for example, uncheck an accidental delete entry</p>
		<textarea id='aggregateSql' class='staticInput' style='overflow:auto;white-space:normal;color:#444;font-size:11px;width:95%;margin:auto;height:200px;' readonly='readonly' onclick='this.select();'></textarea>
		<hr>
		<div class='chklisthdr' style='color:rgb(128,0,0);margin-top:10px;'>
			Filter Log Entries to adjust SQL Log Summary for Record $record
		</div>
		<div style='margin-bottom: 5px;'>Check 
			<a href=\"javascript:$('#table-sql_log_results input:checked').prop('checked',false);updateAggregateSql();\">None</a> | 
			<a href=\"javascript:$('#table-sql_log_results input:checkbox').prop('checked',true);updateAggregateSql();\">All</a>
			<span style='padding-left: 25px; font-size: smaller; font-weight:bold;'>Filter/Search &nbsp;
				<input type='text' id='{$grid_name}_search' size='30' class='x-form-text x-form-field' style='font-family:arial;'>
				(keep in mind filtered checked items will still appear in aggregate window)
			</span>
		</div>
		<script type='text/javascript'>
			$(document).ready(function() {
				// Activate table search
				$('#{$grid_name}_search').quicksearch('table#table-{$grid_name} tbody tr');
				// Re-activate table search when table is re-sorted
				$('#{$grid_name} .hDivBox').click(function() {
					$('#{$grid_name}_search').quicksearch('table#table-{$grid_name} tbody tr');
				});
			});
		</script>
	";

	// Make form to push back selecte SQL
	//	print "<form method='POST'>";

	// Render the data list as flexgrid table
	renderGrid($grid_name, $title." (".count($flex_data)." records)", $width, $height, $flex_headers, $flex_data);

	// Allow whitespace to wrap...
	//print "<script type='text/javascript'> $('.flexigrid div.bDiv td').css({'white-space':'normal'}); </script>";
	?>
		<script type='text/javascript'>
			// Function for updating aggregate window
			function updateAggregateSql() {
				// Empty textarea
				$('#aggregateSql').text('start transaction;\n');	
		
				// Iterate through selected cells to update
				var cells = $('#table-sql_log_results tr').filter(function(index) {
					return $('input:checkbox:checked', this).length; 
				}).each(function(index){
					var e = $('div.sql', this);
					//console.log(e);
					var sql = $(e).text(); // + \"\\n\";
					var sql_comment = $(e).attr('title');
					$('#aggregateSql').append('\n' + sql_comment + '\n' + sql + '\n');
				});
				$('#aggregateSql').append('\n-- test with rollback first to check for errors.  If all goes well, comment out rollback and uncomment commit.\nrollback;\n-- commit;');
				
			}
			
			$(document).ready(function() {
				// Update aggregate window first time
				$('.flexigrid div.bDiv td .sql').css({'overflow':'scroll'}); 
				updateAggregateSql();
			});
			
		</script>
	<?php
} // END OF POST


// takes a multi-line sql string and checks for redcap_data...
function fixInstanceIssue($sql) {
	// If called, it will add "(project_id, event_id, record, field_name, value) " to any insert missing it.
	$count = 0;
	$sql = str_replace("INSERT INTO redcap_data VALUES", "INSERT INTO redcap_data (project_id, event_id, record, field_name, value) VALUES", $sql, $count);
	if ($count > 0) $sql = "-- $count redcap inserts were modified for compatibility with the instance column in this entry\n" . $sql; 
	return $sql;
}