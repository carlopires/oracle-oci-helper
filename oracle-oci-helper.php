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
	 
	public function row() {
		return $this->metadata->rows[$this->row_num];
	}
	 
	public function object() {
		$obj = new stdClass();
		foreach($this->metadata->column_names as $n => $fieldname)
			$obj->{$fieldname} = $this->{$fieldname};
		return $obj;
	}

	public function delete($tablename = null) {
		$table = is_string($tablename) ? $tablename : $this->metadata->tablename;
		if (!$table)
			throw new Exception("Missing table name in delete operation");
	
		$sql = "DELETE FROM $table WHERE ID=:id";
	
		$params = array();
		$params['id'] = $this->ID;
		
		$this->db->query($sql, $params);
	}
	
	public function update($tablename = null) {
		$table = is_string($tablename) ? $tablename : $this->metadata->tablename;
		if (!$table)
			throw new Exception("Missing table name in update operation");

		$sql = "UPDATE $table SET\n";
		
		$params = array();
		$first_field = true;
		
		foreach($this->metadata->column_names as $n => $fieldname) {			
			if ($fieldname == 'ID')
				continue;
			
			else if (!array_key_exists($fieldname, $this->updated))
				continue;
			
			else {
				$param_name = strtolower($fieldname);
				$params[$param_name] = $this->parse_value($fieldname, $this->updated[$fieldname]);

				if (!$first_field)
					$sql .= ", ";
				else
					$first_field = false;
				
				$sql .= "\t$fieldname=:$param_name\n";
			}
		}
		
		$sql .= "WHERE ID=:id";
		$params['id'] = $this->ID;
		
		$this->db->query($sql, $params);
	}
	
	public function parse_value($fieldname, $value) {
		$fieldtype = $this->metadata->fieldtype($fieldname);
		
		if (strpos($fieldtype, 'NUMBER') !== false) {
			if (strlen($value) == 0)
				$value = null;
			
		} else if ($fieldtype == 'DATE') {
			if (strlen($value) == 0)
				$value = null;
		}

		return $value;
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

	private $is_debugging;
	private $is_connected;
	private $debug_handle = null;

	public $connection = null;
	public $autocommit = true;
	private $last_inserted_id;

	private $statement;

	public function __construct($config, $debug = false, $debug_filename = '/tmp/oracle-debug.sql') {
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
		
		$this->is_debugging = $debug;
		$this->is_connected = false;

		if ($this->is_debugging)
			$this->debug_handle = fopen($debug_filename, "a");

		$this->connect();
	}

	public function __destruct() {
		if ($this->is_debugging && $this->debug_handle)
			fclose($this->debug_handle);
	}
	
	public function debug($message) {
		if ($this->is_debugging)
			fwrite($this->debug_handle, "$message\n");
	}

	/*
	 * Initializes Oracle connection.
	*/
	public function connect($force = false) {
		if ($force)
			$this->is_connected = false;

		if (!$this->is_connected) {
			$ORACLE_DSN = "
			(DESCRIPTION =
				(ADDRESS = (PROTOCOL = TCP)(HOST = $this->ORACLE_HOST)(PORT = $this->ORACLE_PORT))
				(CONNECT_DATA = (SID = $this->ORACLE_SID)) )";
				
			$this->connection = oci_connect($this->ORACLE_USER, $this->ORACLE_PASSWORD, $ORACLE_DSN, $this->ORACLE_NLS_CHARACTERSET);
				
			if (!$this->connection) {
				//$e = oci_error();
				//print_r($e);
				$this->is_connected = false;
			} else {
				$s1 = oci_parse($this->connection, "alter session set nls_date_format='$this->ORACLE_NLS_DATE_FORMAT'");
				$s2 = oci_parse($this->connection, "alter session set nls_timestamp_format='$this->ORACLE_NLS_TIMESTAMP_FORMAT'");
				$s3 = oci_parse($this->connection, "alter session set nls_language='$this->ORACLE_NLS_LANGUAGE'");
				$s4 = oci_parse($this->connection, "alter session set nls_territory='$this->ORACLE_NLS_TERRITORY'");
				$this->is_connected = oci_execute($s1) && oci_execute($s2) && oci_execute($s3) && oci_execute($s4);
			}
		}
	}

	/*
	 * Run a SQL query.
	*/
	public function query($sql, $params = null) {
		$this->connect();
		try {
			$this->debug("New query:");
			
			$is_insert = preg_match('/^insert/i', $sql);
	
			if ($is_insert)
				$sql .= ' RETURNING ID INTO :new_id';
			
			$this->debug($sql);
			$this->statement = oci_parse($this->connection, $sql);
	
			if ($params) {
				$this->debug("Binding parameters:");

				foreach ($params as $key => $value) {
					// Creates a variable to the parameter because
					// the oci_execute() function requires that the
					// variable used in oci_bind_by_name() exists
					// in same scope of oci_execute().
					${'bv_' . $key} = $value;
					oci_bind_by_name($this->statement,  ":$key", ${'bv_' . $key});
					
					$this->debug("\t:$key => $value");
				}
			}
	
			if ($is_insert)
				oci_bind_by_name($this->statement, ':new_id', $this->last_inserted_id, 20, SQLT_INT);
				
			if ($this->is_debugging) {
				if (!$this->autocommit)
					$result = oci_execute($this->statement, OCI_NO_AUTO_COMMIT);
				else
					$result = oci_execute($this->statement, OCI_COMMIT_ON_SUCCESS);					
			} else {
				if (!$this->autocommit)
					$result = @oci_execute($this->statement, OCI_NO_AUTO_COMMIT);
				else
					$result = @oci_execute($this->statement, OCI_COMMIT_ON_SUCCESS);
			}
				
			if (!$result) {
				$error = oci_error($this->statement);
				$err_msg = $error['message'];
				$err_sql = $error['sqltext'];
				$err_code = $error['code'];
				throw new Exception("Query error (1): [$err_code] $err_msg\n$err_sql\n");
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
		return $rows;
	}

	/*
	 * Relates each row returned by a query with a PHP object. 
	*/
	public function objects($tablename = null) {
		$this->num_rows = oci_num_rows($this->statement);

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

			$rows = $this->rows();
			$metadata = new OracleMetadata($column_names, $column_types,
					$column_sizes, $rows, $tablename);
				
			$objects = array();
			foreach($rows as $row_num => $value)
				$objects[$row_num] = new OracleObject($this, $metadata, $row_num);

			$nrows = count($objects);
			$this->debug("Resultset: $nrows");

			//oci_free_statement($this->statement);
			return $objects;
		} else
			return array();
	}
	
	/*
	 * Fetch only one row and returns it. Raise an
	 * exception if there is more than one row.
	 */
	public function object($tablename = null) {
		$rows = $this->objects($tablename);
		if (count($rows) == 1)
			return $rows[0];
		else
			throw new Exception('It is expected only one object');
	}

	public function drop_table_if_exists($tablename) {
		$this->query("
				begin
					execute immediate 'DROP TABLE $tablename';
				exception
					when others then
						if SQLCODE != -942 then
							raise;
						end if;
				end;
		");
	}
	
	/*
	 * Fetch the error code related to last executed query.
	*/
	public function error_num() {
		$error = oci_error($this->statement);
		if (is_array($error))
			return $error['code'];
		else
			return null;
	}

	/*
	 * Fetch the error message related to last executed query. 
	*/
	public function error_msg() {
		$error = oci_error($this->statement);
		if (is_array($error))
			return $error['message'];
		else
			return null;
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
		$this->is_connected = false;
		$this->connection = null;
		return $result;
	}
}


