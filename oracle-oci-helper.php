<?php
/*
* Helper classes to easy Oracle conectivity with PHP and OCI.
* Requirements: PHP5+ and OCI8
*
* Carlo Pires, carlopires@gmail.com, 22-FEB-2013
* https://github.com/carlopires/oracle-oci-helper
*
* Example:
* 
*	$oracle_config = array(
*			"ORACLE_HOST" => "127.0.0.1",
*			"ORACLE_SID" => "ex",
*			"ORACLE_USER" => "sysdba",
*			"ORACLE_PASSWORD" => "masterkey",
*	);
*
*	$db = new OracleConnection($oracle_config);
*	$objects = $db->query('select * from USERS')->objects('USERS');
*
*	foreach($objects as $nrow => $ob) {
*	    print $ob->ID . ' - ' . $ob->NAME;
*	}
*
*	// update record
*	$ob->ENABLED = 1;
*	$ob->update()
*
*	// delete record
*	$ob->delete();
*/
class OracleMetadata {
	public $column_names;
	public $column_types;
	public $column_sizes;
	public $rows;
	public $tablename;

	public function __construct($colnames, $coltypes, $colsizes, $rows, $tablename = null) {
		$this->column_names = $colnames;
		$this->column_types = $coltypes;
		$this->column_sizes = $colsizes;
		$this->rows = $rows;
		$this->tablename = $tablename;
	}
	
	public function fieldtype($name) {
		$npos = array_search($name, $this->column_names);
		if ($npos === false)
			throw new Exception("Invalid field name: $name");
		
		return $this->column_types[$npos];
	}
}

class OracleObject {
	const CSV_DELIMITER = ',';
	const CSV_ENCLOSURE = '"';
	const CSV_LINEFEED = "\r\n";
	
	public $db;
	public $metadata;
	public $row_num;
	
	// used in update operations
	public $updated = array();
	
	public function __construct($db, $metadata, $row_num) {
		$this->db = $db;
		$this->metadata = $metadata;
		$this->row_num = $row_num;
	}

	public function __get($name) {
		if (array_key_exists($name, $this->updated))
			return $this->updated[$name];
		
		$npos = array_search($name, $this->metadata->column_names);
		if ($npos === false)
			throw new Exception("Invalid field name: $name");
		
		$row = $this->metadata->rows[$this->row_num];
		return $row[$npos-1];
	}
	 
	public function __set($name, $value) {
		$this->updated[$name] = $value;		
	}
	
	public function has_fieldname($name) {
		return array_search($name, $this->metadata->column_names) !== false;
	}

	public function nvl($name, $default_value) {
		$value = $this->has_fieldname($name) ? $this->{$name} : null;
		return is_null($value) ? $default_value : $value;
	}
	
	public function row() {
		return $this->metadata->rows[$this->row_num];
	}

	public function object() {
		$fieldnames = array_merge(array_values($this->metadata->column_names), array_keys($this->updated));
		
		$obj = new stdClass();
		foreach($fieldnames as $fieldname)
			$obj->{$fieldname} = $this->{$fieldname};
		return $obj;
	}

	/*
	* Return a value enclosed as CSV data 
	*/
	private function csv_enclosed_value($value = null) {
		if ($value !== null && $value != '' ) {
			$csv_delimiter = static::CSV_DELIMITER;
			$csv_enclosure = static::CSV_ENCLOSURE;
			
			$delimiter = preg_quote($csv_delimiter, '/');
			$enclosure = preg_quote($csv_enclosure, '/');
			
			if (preg_match("/".$delimiter."|".$enclosure."|\n|\r/i", $value) || ($value{0} == ' ' || substr($value, -1) == ' ') ) {
				$value = str_replace($csv_enclosure, $csv_enclosure.$csv_enclosure, $value);
				$value = $csv_enclosure.$value.$csv_enclosure;
			}
		}
		return $value;
	}
	
	/*
	* Returns this record encoded as CSV data
	*/
	public function csv() {
		foreach($this->metadata->column_names as $n => $fieldname) {
			$value = $this->{$fieldname};
			$entry[] = $this->csv_enclosed_value($value);
		}
		return implode(static::CSV_DELIMITER, $entry).static::CSV_LINEFEED;
	}

	/*
	* Returns field names of this record encoded 
	* as CSV header
	*/
	public function csv_header() {
		foreach($this->metadata->column_names as $n => $fieldname) {
			$entry[] = $this->csv_enclosed_value($fieldname);
		}
		return implode(static::CSV_DELIMITER, $entry).static::CSV_LINEFEED;
	}
	
	/*
	* Delete this record using ID field and table name, if available 
	*/
	public function delete($tablename = null) {
		$table = is_string($tablename) ? $tablename : $this->metadata->tablename;
		if (!$table)
			throw new Exception("Missing table name in delete operation");
	
		$sql = "DELETE FROM $table WHERE ID=:id";
	
		$params = array();
		$params['id'] = $this->ID;
		
		$this->db->query($sql, $params);
	}
	
	/*
	* Update this record using ID field and table name, if available 
	*/
	public function update($tablename = null) {
		$table = is_string($tablename) ? $tablename : $this->metadata->tablename;
		if (!$table)
			throw new Exception("Missing table name in update operation");

		$sql = "UPDATE $table SET\n";
		
		$params = array();
		$first_field = true;
		$updated = 0;
		
		foreach($this->metadata->column_names as $n => $fieldname) {			
			if ($fieldname == 'ID')
				continue;
			
			else if (!array_key_exists($fieldname, $this->updated))
				continue;
			
			else {
				$param_name = strtolower($fieldname);
				$fieldtype = $this->metadata->fieldtype($fieldname);
				$param_value = $params[$param_name] = $this->db->parsed_value($fieldname, $fieldtype, $this->updated[$fieldname]);

				if (!$first_field)
					$sql .= ",\n\t";
				else {
					$sql .= "\t";
					$first_field = false;
				}
				
				$sql .= $this->db->sql_field_param($fieldname, $fieldtype, $param_name, $param_value, true);
				$updated++;
			}
		}
		
		if ($updated == 0)
			throw new Exception("None field to update");
		
		$sql .= "\n WHERE\n\tID = :id\n";
		
		$params['id'] = $this->ID;
		
		$this->db->query($sql, $params);
	}	
}

class OracleConnection {
	private $ORACLE_HOST = "127.0.0.1";
	private $ORACLE_PORT = "1521";
	private $ORACLE_SID = "ex";
	private $ORACLE_USER = "sysdba"; 
	private $ORACLE_PASSWORD = "";

	private $ORACLE_NLS_DATE_FORMAT = "DD/MM/YYYY";
	private $ORACLE_NLS_TIMESTAMP_FORMAT = "DD/MM/YYYY HH24:MI:SS";
	private $ORACLE_NLS_LANGUAGE = 'BRAZILIAN PORTUGUESE';
	private $ORACLE_NLS_TERRITORY = 'BRAZIL';
	private $ORACLE_NLS_CHARACTERSET = 'WE8ISO8859P1';
	
	private $TIMEZONE = 'America/Sao_Paulo';

	private $debugging;
	private $connected;
	private $debug_handle = null;

	public $connection = null;
	public $autocommit = true;
	private $last_inserted_id;

	private $statement;

	public function __construct($config, $debug = false, $debug_filename = null) {
		if (isset($config['ORACLE_HOST']))
			$this->ORACLE_HOST = $config['ORACLE_HOST'];
		
		if (isset($config['ORACLE_PORT']))
			$this->ORACLE_PORT = $config['ORACLE_PORT'];
		
		if (isset($config['ORACLE_SID']))
			$this->ORACLE_SID = $config['ORACLE_SID'];
		
		if (isset($config['ORACLE_USER']))
			$this->ORACLE_USER = $config['ORACLE_USER'];
		
		if (isset($config['ORACLE_PASSWORD']))
			$this->ORACLE_PASSWORD = $config['ORACLE_PASSWORD'];
		
		if (isset($config['ORACLE_NLS_DATE_FORMAT']))
			$this->ORACLE_NLS_DATE_FORMAT = $config['ORACLE_NLS_DATE_FORMAT'];
		
		if (isset($config['ORACLE_NLS_TIMESTAMP_FORMAT']))
			$this->ORACLE_NLS_TIMESTAMP_FORMAT = $config['ORACLE_NLS_TIMESTAMP_FORMAT'];
		
		if (isset($config['ORACLE_NLS_LANGUAGE']))
			$this->ORACLE_NLS_LANGUAGE = $config['ORACLE_NLS_LANGUAGE'];

		if (isset($config['ORACLE_NLS_TERRITORY']))
			$this->ORACLE_NLS_TERRITORY = $config['ORACLE_NLS_TERRITORY'];
		
		if (isset($config['ORACLE_NLS_CHARACTERSET']))
			$this->ORACLE_NLS_CHARACTERSET = $config['ORACLE_NLS_CHARACTERSET'];

		if (isset($config['TIMEZONE']))
			$this->TIMEZONE = $config['TIMEZONE'];
		
		date_default_timezone_set($this->TIMEZONE);
		
		$this->debugging = $debug;
		$this->connected = false;
		
		if ($this->debugging) {
			if (is_null($debug_filename)) {
				$debug_filename = '/tmp/oracle-debug-'.date('Y-m-d-H').'.sql';
			}
			$this->debug_handle = fopen($debug_filename, "a");
		}

		$this->connect();
	}

	public function __destruct() {
		if ($this->debugging && $this->debug_handle)
			fclose($this->debug_handle);
	}
	
	public function is_connected() {
		return $this->connected;
	}
	
	public function is_debugging() {
		return $this->debugging;
	}

	public function debug($message) {
		if ($this->debugging)
			fwrite($this->debug_handle, "$message\n");
	}
	
	/*
	* Initializes Oracle connection.
	*/
	public function connect($force = false) {
		if ($force)
			$this->connected = false;

		if (!$this->connected) {
			$ORACLE_DSN = "
			(DESCRIPTION =
				(ADDRESS = (PROTOCOL = TCP)(HOST = $this->ORACLE_HOST)(PORT = $this->ORACLE_PORT))
				(CONNECT_DATA = (SID = $this->ORACLE_SID)) )";
				
			$this->connection = oci_connect($this->ORACLE_USER, $this->ORACLE_PASSWORD, $ORACLE_DSN, $this->ORACLE_NLS_CHARACTERSET);
				
			if (!$this->connection) {
				$this->connected = false;
			} else {
				$conn = $this->connection;
				
				oci_execute(oci_parse($conn, "alter session set nls_language='$this->ORACLE_NLS_LANGUAGE'"));
				oci_execute(oci_parse($conn, "alter session set nls_territory='$this->ORACLE_NLS_TERRITORY'"));
				oci_execute(oci_parse($conn, "alter session set nls_date_format='$this->ORACLE_NLS_DATE_FORMAT'"));
				oci_execute(oci_parse($conn, "alter session set nls_timestamp_format='$this->ORACLE_NLS_TIMESTAMP_FORMAT'"));
				
				$this->connected = true;
				
			}
		}
	}
	
	/*
	* Returns oracle session parameters
	*/	
	public function session_parameters() {
		$this->connect();

		if ($this->connected) {
			$params = array();
			foreach($this->query('select * from v$nls_parameters')->objects() as $p)
				$params[$p->PARAMETER] = $p->VALUE;
			
			return $params;
		} else
			throw new Exception('Could not connect to oracle database');
	}
	
	/*
	* Return the date format in use 
	*/
	function get_date_format() {
		return $this->ORACLE_NLS_DATE_FORMAT;
	}

	/*
	 * Return the timestamp format in use
	*/
	function get_timestamp_format() {
		return $this->ORACLE_NLS_TIMESTAMP_FORMAT;
	}
	
	/*
	* Returns an object that match the parameters
	*/
	public function get($table_name, $params) {
		if (is_numeric($params))
			$params = array('ID' => $params);
		
		else if (!is_array($params))
			throw new Exception('Parameters for get() must be an array');
		
		$sql = "select * from $table_name where";
		$sql_params = array();
		$first = true;
		
		foreach($params as $field_name => $field_value) {
			$param_name = strtolower($field_name);
			$sql_params[$param_name] = $field_value;
			
			if (!$first)
				$sql .= " AND";
			
			$sql .= " $field_name = :$param_name";
			
			$first = false;  
		}
		return $this->query($sql, $sql_params)->object($table_name);
	}

	/*
	* Deletes objects that match the parameters
	*/
	public function delete($table_name, $params) {
		if (!is_array($params))
			throw new Exception('Parameters for delete() must be an array');
	
		$sql = "delete from $table_name";
		$sql_params = array();
		$first = true;
	
		foreach($params as $field_name => $field_value) {
			$param_name = strtolower($field_name);
			$sql_params[$param_name] = $field_value;
				
			if (!$first)
				$sql .= " AND";
			else
				$sql .= " where";
				
			$sql .= " $field_name = :$param_name";
				
			$first = false;
		}
		return $this->query($sql, $sql_params);
	}
	
	/*
	* Returns the next sequence ID for the table
	* sequence passed as parameter.
	*/
	public function next_sequence($table_name) {
		$sql = "select $table_name.NEXTVAL as ID from DUAL";
		return $this->query($sql)->object()->ID;
	}

	/*
	* Insert a new record
	*/
	public function insert($tablename, $param_values) {
		if (!is_string($tablename) || strlen($tablename) == 0)
			throw new Exception("Missing table name in insert operation");

		// Fetch the metadata for this table
		$metadata = $this->query("select * from $tablename where ID = -1")->metadata();
		
		$params = array();
		$fnames = array();
		
		foreach($metadata->column_names as $n => $fieldname) {
			$param_name = strtolower($fieldname);
			
			if (!isset($param_values[$param_name]))
				continue;

			$fieldtype = $metadata->fieldtype($fieldname);
			$param_values[$param_name] = $param_value = $this->parsed_value($fieldname, $fieldtype, $param_values[$param_name]);
			
			$fnames[] = $fieldname;
			$params[] = $this->sql_field_param($fieldname, $fieldtype, $param_name, $param_value, false);
		}

		$sql = "INSERT INTO $tablename \n";
		$sql .= "\t(" . implode(', ', $fnames);
		$sql .= ")\n VALUES \n\t(" . implode(', ', $params) . ")\n";
		
		$this->query($sql, $param_values);
	}

	/*
	* Updates a record using the ID field as parameter
	*/
	public function update($tablename, $param_values) {
		if (!is_string($tablename) || strlen($tablename) == 0)
			throw new Exception("Missing table name in update operation");
	
		// Fetch the metadata for this table
		$metadata = $this->query("select * from $tablename where ID = -1")->metadata();
	
		$params = array();
	
		foreach($metadata->column_names as $n => $fieldname) {
			if ($fieldname == 'ID')
				continue;
				
			$param_name = strtolower($fieldname);
							
			if (!isset($param_values[$param_name]))
				continue;
	
			$fieldtype = $metadata->fieldtype($fieldname);
			$param_values[$param_name] = $param_value = $this->parsed_value($fieldname, $fieldtype, $param_values[$param_name]);
				
			$params[] = $this->sql_field_param($fieldname, $fieldtype, $param_name, $param_value, true);
		}
	
		$sql = "UPDATE $tablename SET\n";
		$sql .= "\t" . implode(",\n\t", $params) . "\n";
		$sql .= "WHERE\n\tID = :id\n";
		
		$this->query($sql, $param_values);
	}
	
	/*
	* Generates a proper SQL string to be used in queries
	* that need to assign values to fields from parameters.  
	*/
	public function sql_field_param(&$fieldname, &$fieldtype, &$param_name, &$param_value, $assign) {
		$sql = $assign ? "$fieldname = " : '';
		
		if ($fieldtype == 'DATE' && !is_null($param_value)) {
			$date_format = $this->get_date_format();
			$timestamp_format = $this->get_timestamp_format();
		
			$param_size = strlen($param_value);
		
			if ($param_size == strlen(preg_replace('/\d/','',$date_format)))
				$param_format = $date_format;
			else if ($param_size == strlen(preg_replace('/\d/','',$timestamp_format)))
				$param_format = $timestamp_format;
			else
				throw new Exception('Invalid date format: '.$param_value);
		
			$sql .= "to_date(:$param_name,'$param_format')";
		
		} else {
			$sql .= ":$param_name";
		}
		return $sql;
	}
	
	/*
	* Utility function aimed to parse values from 
	* parameters and make type conversions.
	*/
	public function parsed_value($fieldname, $fieldtype, $value) {
		if (strpos($fieldtype, 'NUMBER') !== false) {
			if (strlen($value) == 0)
				$value = null;
				
		} else if ($fieldtype == 'DATE') {
			if (strlen($value) == 0)
				$value = null;
		}
		
		return $value;
	}
	
	/*
	* Run a SQL query.
	*/
	public function query($sql, $params = null, $output_inserted_id = false) {
		$this->connect();
		try {
			$this->debug("New query:");
			
			if ($output_inserted_id)
				$sql .= ' RETURNING ID INTO :new_id';
			
			$this->debug($sql);
			
			$this->statement = oci_parse($this->connection, $sql);
			
			if ($this->statement === false) {
				throw new Exception('Could not parse SQL');
			}
	
			if ($params) {
				$this->debug("Binding parameters:");

				foreach ($params as $key => $value) {
					// to avoid PHP warning, only bind if this
					// parameter is in SQL 
					if (strpos($sql, ":$key") === false)
						continue;
					
					// Creates a variable to the parameter because
					// the oci_execute() function requires that the
					// variable used in oci_bind_by_name() exists
					// in same scope of oci_execute().
					${'bv_' . $key} = $value;
					oci_bind_by_name($this->statement,  ":$key", ${'bv_' . $key});
					
					$this->debug("\t:$key => $value");
				}
			}
	
			if ($output_inserted_id)
				oci_bind_by_name($this->statement, ':new_id', $this->last_inserted_id, 20, SQLT_INT);
				
			if (!$this->autocommit)
				$result = @oci_execute($this->statement, OCI_NO_AUTO_COMMIT);
			else
				$result = @oci_execute($this->statement, OCI_COMMIT_ON_SUCCESS);
							
			if (!$result) {
				$error = oci_error($this->statement);
				$err_msg = $error['message'];
				$err_sql = $error['sqltext'];
				$err_code = $error['code'];
				throw new Exception("Query error (1): [$err_code] $err_msg\n$err_sql\n");
			} else {
				// Save the number of rows affected during statement execution
				$this->num_rows = oci_num_rows($this->statement);
				$this->debug("Affected rows: ".$this->num_rows);
			}
			return $this;
				
		} catch (PDOException $e) {
			$err_msg = $e->getMessage();
			$err_code = $e->getCode();
			throw new Exception("Query error (2): [$err_code] $err_msg");
		}
	}

	/*
	* Returns an array with the rows returned by the last query
	* executed.
	*/
	public function rows() {
		$rows = array();
		oci_fetch_all($this->statement, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_NUM);
		
		$this->debug("Fetched rows: ".count($rows));
		
		return $rows;
	}

	/*
	* Returns the Oracle metadata for the last statement 
	* executed.
	*/
	public function metadata($tablename = null) {
		$ncols = oci_num_fields($this->statement);
		if ($ncols) {
			$column_names = array();
			$column_types = array();
			$column_sizes = array();
	
			for ($i = 1; $i <= $ncols; $i++)
				$column_names[$i] = oci_field_name($this->statement, $i);
	
			for ($i = 1; $i <= $ncols; $i++)
				$column_types[$i] = oci_field_type($this->statement, $i);
	
			for ($i = 1; $i <= $ncols; $i++)
				$column_sizes[$i] = oci_field_size($this->statement, $i);
	
			return new OracleMetadata($column_names, $column_types,
					$column_sizes, $this->rows(), $tablename);
		} else
			throw new Exception('No metadata to fetch');
	}
	
	/*
	* Relates each row returned by a query with a PHP object. 
	*/
	public function objects($tablename = null) {
		$metadata = $this->metadata($tablename);
		
		$objects = array();
		foreach($metadata->rows as $row_num => $value)
			$objects[$row_num] = new OracleObject($this, $metadata, $row_num);
				
		//oci_free_statement($this->statement);
		return $objects;
	}

	/*
	* Fetch only one row and returns it. Raise an
	* exception if there is more than one row.
	*/
	public function object_or_null($tablename = null) {
		$rows = $this->objects($tablename);
		$num_rows = count($rows); 
		if ($num_rows == 1)
			return $rows[0];
		
		else if ($num_rows == 0)
			return  null;
		
		else
			throw new Exception('It is expected only one object', 404);
	}
	
	/*
	* Fetch only one row and returns it. Raise an
	* exception if there is more than one row or if 
	* there is none object.
	*/
	public function object($tablename = null) {
		$obj = $this->object_or_null($tablename);
		if (is_null($obj))
			throw new Exception('It was expected one object, not null', 404);
		return $obj;
	}
	
	/*
	* A helper to drop a table by name if it exists.
	* 
	* OBS: DROP TABLE HAVE IMPLICIT COMMIT !!
	*/
	public function drop_table_if_exists($tablename) {
		$this->query("
				begin
					execute immediate 'DROP TABLE $tablename';
				exception
					when others then
						if SQLCODE != -942 then
							raise;
						end if;
				end;");
	}
	
	/*
	* Fetch the error message related to last executed query.
	*/
	public function error() {
		$error = oci_error($this->statement);
		return is_array($error) ? $error : null;
	}
	
	/*
	* Fetch the error code related to last executed query.
	*/
	public function error_num() {
		$error = $this->error();
		return is_array(error) ? $error['code'] : null;
	}

	/*
	* Fetch the error message related to last executed query. 
	*/
	public function error_msg() {
		$error = oci_error($this->statement);
		return is_array($error) ? $error['message'] : null;
	}
	
	/*
	* Exports this query as CSV file
	*/
	public function export($csv_filename) {
		$csv = fopen($csv_filename, 'w+');
		if ($csv !== false) {
			$first = true;
			$n = 0;
			
			foreach($this->objects() as $record) {
				if ($first) {
					fwrite($csv, $record->csv_header());
					$first = false;
				}
			
				fwrite($csv, $record->csv());
				$n++;
			}
			
			fclose($csv);
			return $n;
		}
		return false;
	}

	/*
	* Parses the $sql variable with variables in $vars array.
	* The parse used supports IF clauses to generate 
	* conditionals SQL. See bellow the ParseIf class documentation. 
	*/
	public static function parse_sql($sql, $vars=null) {
		if (is_array($vars)) {
			$p = new ParserIf($vars, false);
			$sql = $p->parse($sql);
	
			foreach($vars as $name => $value) {
				if ($name[0] != '$')
					throw new Exception(sprintf('The variable SQL "%s" must starts with $', $name));
				else
					$sql = str_replace($name, $value, $sql);
			}
		}
		return $sql;
	}
	
	/*
	* Commit changes pending in the oracle connection
	*/
	public function commit() {
		return oci_commit($this->connection);
	}
	
	/*
	* Rollback changes pending in the oracle connection
	*/
	public function rollback() {
		return oci_rollback($this->connection);
	}
	
	/*
	* Closes the oracle connection
	*/
	public function close() {
		$result = oci_close($this->connection);
		$this->connected = false;
		$this->connection = null;
		return $result;
	}
}

/*
* ParseIf - A PHP parser for IF clauses
* Carlo Pires <carlopires@gmail.com>
*
* This class parses the following construction
* (inspired by Django templates):
*
* {% if <php condition> %}
* {% else %}
* {% endif %}
*
* Example:
*
*    $v = file_get_contents('select-customer.sql');
*
*    $p = new ParserIf(array(
*        'include_ids' => true,
*        'include_description' => true,
*    ));
*
*    print $p->parse($v);
*
*    // Variable names can also start with '$'
*    // for instance:
*    $p = new ParserIf(array(
*        '$include_ids' => true,
*        '$include_description' => true,
*    ));
*
*
* The contents of select-customer.sql could be:
*
*    select
*    {% if $include_ids %}
*        ID,
*    {% else %}
*        NUMBER,
*    {% endif %}
*        NAME,
*    {% if $include_description %}
*        DESCRIPTION,
*    {% endif %}
*        EMAIL
*    from
*        customers;
*/
class ParserIf {
	private $variables;
	private $debug;

	public function __construct($variables = null, $debug = true) {
		$this->variables = $variables;
		$this->debug = $debug;
	}
	
	private function next_endif(&$text, $start) {
		$end = null;
		$n = 0;
		$pos = $start+1;
		while (true) {
			$next_if = strpos($text, '{% if ', $pos);
			$next_endif = strpos($text, '{% endif %}', $pos);
		
			if ($next_endif === false)
				break;
		
			if ($next_if !== false && $next_if < $next_endif) {
				$n++;
				$pos = $next_if+1;
			} else {
				$end = $next_endif;
					
				if ($n == 0)
					break;
				else {
					$n--;
					$pos = $next_endif+1;
				}
			}
		}
		return $end;
	}
	
	function parse_else(&$text, $start = 0) {
		$else = strpos($text, '{% else %}', $start);
		if ($else !== false) {
			$next_if = strpos($text, '{% if ', $start);
			if ($next_if !== false && $next_if < $else) {
				if (($end = $this->next_endif($text, $next_if)) !== false)
					return $this->parse_else($text, $end+1);
			} else
				return $else;
		}
		return false;
	}

	function parse_if(&$text) {
		$start = strpos($text, '{% if ');
		if ($start !== false) {
			if (($end = $this->next_endif($text, $start)) !== false) {
				// extracts the condition
				$pos = strpos($text, '%}', $start+1);
				if ($pos !== false ) {

					$before = substr($text, 0, $start-1);
					$condition = substr($text, $start+6, $pos-($start+6));

					$valid = substr($text, $pos+2, $end-$pos-2);

					if (($else = $this->parse_else($valid)) !== false) {
						$invalid = substr($valid, $else+10);
						$valid = substr($valid, 0, $else-1);
					} else
						$invalid = '';
						
					$after = substr($text, $end+11);

					return array(
							'before' => $before,
							'condition' => $condition,
							'valid' => $valid,
							'invalid' => $invalid,
							'after' => $after,
					);
				}
			}
		}
		return $text;
	}

	function parse($text, $variables=null) {
		// set global variables
		if (is_array($this->variables))
			foreach($this->variables as $name => $value) {
			if ($name[0] == '$')
				$name = substr($name, 1);
			$$name = $value;
		}

		// set local variables
		if (is_array($variables))
			foreach($variables as $name => $value) {
			if ($name[0] == '$')
				$name = substr($name, 1);
			$$name = $value;
		}

		// disable output if no debug
		if (!$this->debug)
			$err_level = error_reporting(0);

		// parse ifs
		while (is_array($parsed = $this->parse_if($text))) {
			try {
				$valid = eval('return ' . $parsed['condition'] . ';');
			} catch (Exception $e) {
				$valid = false;
			}

			$text = $parsed['before'] .
			$parsed[($valid ? 'valid' : 'invalid')] .
			$parsed['after'];
		}

		// reset error output if no debug
		if (!$this->debug)
			error_reporting($err_level);

		// return parsed text
		return $parsed;
	}
}