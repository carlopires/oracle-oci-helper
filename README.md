oracle-oci-helper
=================

PHP helper classes for Oracle OCI connections

This script provides the OracleConnection class, which aims 
to help run SQL queries and manipulate Oracle tables as 
PHP objects.

OBS: This class requires that all tables have an ID field
as primary key.

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

## License

(The MIT License)

Copyright (C) 2013 by Carlo Pires <carlopires@gmail.com> 

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
THE SOFTWARE
