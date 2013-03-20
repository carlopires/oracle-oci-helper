oracle-oci-helper
=================

PHP helper classes for Oracle OCI connections

This script provides the OracleConnection class, which aims 
to help run SQL queries and manipulate Oracle tables as 
PHP objects.

Example:
    
    <?php
    require "oracle-oci-helper.php";
    
    $oracle_config = array(
        "ORACLE_HOST" => "127.0.0.1",
        "ORACLE_SID" => "ex",
        "ORACLE_USER" => "sysdba",
        "ORACLE_PASSWORD" => "masterkey",
    );
    
    $db = new OracleConnection($oracle_config);
    $objects = $db->query('select * from USERS')->objects();
    
    foreach($objects as $nrow => $ob) {
        print $ob->ID . ' - ' . $ob->NAME;
    }
    
    // update record
    $ob->ENABLED = 1;
    $ob->update('USERS')

    // delete record
    $ob->delete('USERS');
    
    // is possible to avoid to pass the table name each time you 
    // need to update or delete by passing it in objects() method
    
    $objects = $db->query('select * from USERS')->objects('USERS');
    $ob = $objects[0];
    $ob->ENABLED = false;
    $ob->update();
    
    $objects[1]->delete();

    // using transactions
    
    $ob->autocommit = false;
    ... after changes
    $ob->commit() // or $ob->rollback();

