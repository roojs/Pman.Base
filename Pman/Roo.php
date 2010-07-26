<?php


require_once 'Pman.php';
/**
 * 
 * 
 * not really sure how save our factory method is....!!!
 * 
 * 
 * Uses these methods of the dataobjects:
 * - checkPerm('L'/'E'/'A', $authuser) - can we list the stuff
 * 
 * - applySort($au, $sortcol, $direction)
 * - applyFilters($_REQUEST, $authUser) -- apply any query filters on data. and hide stuff not to be seen.
 * - postListExtra - add extra column data on the results (like new messages etc.)
 * - postListFilter - add extra data to an object
 * 
 * - toRooSingleArray() // single fetch, add data..
 * - toRooArray() /// toArray if you need to return different data.. for a list fetch.
 * 
 * 
 * - beforeDelete() -- return false for fail and set DO->err;
 * - onUpdate($old, $request,$roo) - after update
 * - onInsert($request,$roo) - after insert
 * - onUpload($roo)
 * - setFromRoo($ar) - values from post (deal with dates etc.) - return true|error string.
 * 
 * - toEventString (for logging)
 */

class Pman_Roo extends Pman
{
    
   function getAuth() {
        parent::getAuth(); // load company!
        $au = $this->getAuthUser();
        if (!$au) {
            $this->jerr("Not authenticated", array('authFailure' => true));
        }
        $this->authUser = $au;
        return true;
    }
    /**
     * GET method   Roo/TABLENAME.php 
     * -- defaults to listing data. with args.
     * 
     * other opts:
     * _post      = simulate a post with debuggin on.
     * lookup     =  array( k=>v) single fetch based on a key/value pair
     * _id        =  single fetch based on id.
     * _delete    = delete a list of ids element. (seperated by ,);
     * _columns   = comma seperated list of columns.
     * csvCols    = return data as csv
     * csvTitles  = return data as csv
     *
     * sort        = sort column
     * dir         = sort direction
     * start       = limit start
     * limit       = limit number 
     * 
     * _toggleActive !:!:!:! - thsi hsould not really be here..
     * query[add_blank] - add a line in with an empty option...  - not really needed???
     * 
     */
    function get($tab)
    {
         //  $this->jerr("Not authenticated", array('authFailure' => true));
         //DB_DataObject::debuglevel(1);
        
        // debugging...
        if (!empty($_GET['_post'])) {
            $_POST  = $_GET;
            DB_DAtaObject::debuglevel(1);
            return $this->post($tab);
        }
        $tab = str_replace('/', '',$tab); // basic protection??
        $x = DB_DataObject::factory($tab);
        if (!is_a($x, 'DB_DataObject')) {
            $this->jerr('invalid url');
        }
        $_columns = !empty($_REQUEST['_columns']) ? explode(',', $_REQUEST['_columns']) : false;
        
        if (isset( $_REQUEST['lookup'] )) { // single fetch based on key/value pairs
            if (method_exists($x, 'checkPerm') && !$x->checkPerm('S', $this->authUser))  {
                $this->jerr("PERMISSION DENIED");
            }
            $this->loadMap($x, $_columns);
            $x->setFrom($_REQUEST['lookup'] );
            $x->limit(1);
            if (!$x->find(true)) {
                $this->jok(false);
            }
            $this->jok($x->toArray());
        }
        
        
        
        if (isset($_REQUEST['_id'])) { // single fetch
            
            if (empty($_REQUEST['_id'])) {
                $this->jok($x->toArray());  // return an empty array!
            }
           
            $this->loadMap($x, $_columns);
            
            if (!$x->get($_REQUEST['_id'])) {
                $this->jerr("no such record");
            }
            
            if (method_exists($x, 'checkPerm') && !$x->checkPerm('S', $this->authUser))  {
                $this->jerr("PERMISSION DENIED");
            }
            
            $this->jok(method_exists($x, 'toRooSingleArray') ? $x->toRooSingleArray($this->authUser) : $x->toArray());
            
        }
        if (isset($_REQUEST['_delete'])) {
            // do we really delete stuff!?!?!?
           
            
            $clean = create_function('$v', 'return (int)$v;');
            
            $bits = array_map($clean, explode(',', $_REQUEST['_delete']));
            $x->whereAdd('id IN ('. implode(',', $bits) .')');
            $x->find();
            $errs = array();
            while ($x->fetch()) {
                $xx = clone($x);
                
                if (method_exists($x, 'checkPerm') && !$x->checkPerm('D', $this->authUser))  {
                    $this->jerr("PERMISSION DENIED");
                }
                
                $this->addEvent("DELETE", $x, $x->toEventString());
                if ( method_exists($xx, 'beforeDelete') && ($xx->beforeDelete() === false)) {
                    $errs[] = "Delete failed ({$xx->id})\n". (isset($xx->err) ? $xx->err : '');
                    continue;
                }
                $xx->delete();
            }
            if ($errs) {
                $this->jerr(implode("\n<BR>", $errs));
            }
            $this->jok("Deleted");
            
        }
        if (isset($_REQUEST['_toggleActive'])) {
            // do we really delete stuff!?!?!?
            if (!$this->hasPerm("Core.Staff", 'E'))  {
                $this->jerr("PERMISSION DENIED");
            }
            $clean = create_function('$v', 'return (int)$v;');
            $bits = array_map($clean, explode(',', $_REQUEST['_toggleActive']));
            if (in_array($this->authUser->id, $bits) && $this->authUser->active) {
                $this->jerr("you can not disable yourself");
            }
            $x->query('UPDATE Person SET active = !active WHERE id IN (' .implode(',', $bits).')');
            $this->addEvent("USERTOGGLE", false, implode(',', $bits));
            $this->jok("Updated");
            
        }
      // DB_DataObject::debugLevel(1);
        if (method_exists($x, 'checkPerm') && !$x->checkPerm('S', $this->authUser))  {
            $this->jerr("PERMISSION DENIED");
        }
        $map = $this->loadMap($x, $_columns);
        
        $this->setFilters($x,$_REQUEST);
        
         
        
        // build join if req.
        
        $total = $x->count();
        // sorting..
      //   DB_DataObject::debugLevel(1);
        
        $sort = empty($_REQUEST['sort']) ? '' : $_REQUEST['sort'];
        $dir = (empty($_REQUEST['dir']) || strtoupper($_REQUEST['dir']) == 'ASC' ? 'ASC' : 'DESC');
        
        $sorted = false;
        if (method_exists($x, 'applySort')) {
            $sorted = $x->applySort($this->authUser, $sort, $dir, $this->cols);
        }
        if ($sorted === false) {
            
            $cols = $x->table();
           // echo '<PRE>';print_r(array($sort, $this->cols));
            // other sorts??? 
           // $otherSorts = array('person_id_name');
            
            if (strlen($sort) && isset($cols[$sort]) ) {
                $sort = $x->tableName() .'.'.$sort . ' ' . $dir ;
                $x->orderBy($sort );
            } else if (in_array($sort, $this->cols)) {
                $sort = $sort . ' ' . $dir ;
                $x->orderBy($sort );
            }// else other formatas?
            //if ( in_array($sort, $otherSorts)) {
            //    $x->orderBy($sort . ' ' . $dir);
            ////}
        }
        
        
 
        $x->limit(
            empty($_REQUEST['start']) ? 0 : (int)$_REQUEST['start'],
            min(empty($_REQUEST['limit']) ? 25 : (int)$_REQUEST['limit'], 1000)
        );
        
        $queryObj = clone($x);
        
        $x->find();
        $ret = array();
        
        if (!empty($_REQUEST['query']['add_blank'])) {
            $ret[] = array( 'id' => 0, 'name' => '----');
            $total+=1;
        }
        // MOVE ME...
        
        //if (($tab == 'Groups') && ($_REQUEST['type'] != 0))  { // then it's a list of teams..
        if ($tab == 'Groups') {
            
            $ret[] = array( 'id' => 0, 'name' => 'EVERYONE');
            $ret[] = array( 'id' => -1, 'name' => 'NOT_IN_GROUP');
            //$ret[] = array( 'id' => 999999, 'name' => 'ADMINISTRATORS');
            $total+=2;
        }
        
        // DOWNLOAD...
        
        if (!empty($_REQUEST['csvCols']) && !empty($_REQUEST['csvTitles']) ) {
            header('Content-type: text/csv');
            
            header('Content-Disposition: attachment; filename="documentlist-export-'.date('Y-m-d') . '.csv"');
            //header('Content-type: text/plain');
            $fh = fopen('php://output', 'w');
            fputcsv($fh, $_REQUEST['csvTitles']);
            while ($x->fetch()) {
                //echo "<PRE>"; print_r(array($_REQUEST['csvCols'], $x->toArray())); exit;
                $line = array();
                foreach($_REQUEST['csvCols'] as $k) {
                    $line[] = isset($x->$k) ? $x->$k : '';
                }
                fputcsv($fh, $line);
            }
            fclose($fh);
            exit;
            
        
        }
        //die("DONE?");
        
        $rooar = method_exists($x, 'toRooArray');
        while ($x->fetch()) {
            $add = $rooar  ? $x->toRooArray() : $x->toArray();
            
            $ret[] =  !$_columns ? $add : array_intersect_key($add, array_flip($_columns));
        }
        //if ($x->tableName() == 'Documents_Tracking') {
        //    $ret = $this->replaceSubject(&$ret, 'doc_id_subject');
       // }
        
        $extra = false;
        if (method_exists($queryObj ,'postListExtra')) {
            $extra = $queryObj->postListExtra($_REQUEST);
        }
        // filter results, and add any data that is needed...
        if (method_exists($x,'postListFilter')) {
            $ret = $x->postListFilter($ret, $this->authUser, $_REQUEST);
        }
        
        
        
       // echo "<PRE>"; print_r($ret);
        $this->jdata($ret,$total, $extra );

    
    }
     /**
     * POST method   Roo/TABLENAME.php 
     * -- updates the data..
     * 
     * other opts:
     * _debug - forces debugging on.
     * _get - forces a get request
     * {colid} - forces fetching
     * 
     */
    function post($tab) // update / insert (?? dleete??)
    {
       // DB_DataObject::debugLevel(1);
        if (!empty($_REQUEST['_debug'])) {
            DB_DataObject::debugLevel(1);
        }
        
        if (!empty($_REQUEST['_get'])) {
            return $this->get($tab);
        }
        $_columns = !empty($_REQUEST['_columns']) ? explode(',', $_REQUEST['_columns']) : false;
        
        $tab = str_replace('/', '',$tab); // basic protection??
        $x = DB_DataObject::factory($tab);
        if (!is_a($x, 'DB_DataObject')) {
            $this->jerr('invalid url');
        }
        // find the key and use that to get the thing..
        $keys = $x->keys();
        if (empty($keys) ) {
            $this->jerr('no key');
        }
        $old = false;
        
        if (!empty($_REQUEST[$keys[0]])) {
            // it's a create..
            $x->get($keys[0], $_REQUEST[$keys[0]]);
            if (method_exists($x, 'checkPerm') && !$x->checkPerm('E', $this->authUser, $_REQUEST))  {
                $this->jerr("PERMISSION DENIED");
            }
            
            $old = clone($x);
        } else {
            if (method_exists($x, 'checkPerm') && !$x->checkPerm('A', $this->authUser, $_REQUEST))  {
                $this->jerr("PERMISSION DENIED");
            }
        }
        $this->old = $old;
         
        if (method_exists($x, 'setFromRoo')) {
            $res = $x->setFromRoo($_REQUEST, $this);
            if (is_string($res)) {
                $this->jerr($res);
            }
        } else {
            $x->setFrom($_REQUEST);
        }
        
        
        /*
        check perm accepts the changes  - so no need to review twice!!!
        if (!empty($_POST[$keys[0]])) {
            if (method_exists($x, 'checkPerm') && !$x->checkPerm('E', $this->authUser))  {
                $this->jerr("PERMISSION DENIED");
            }
        }  else {
            if (method_exists($x, 'checkPerm') && !$x->checkPerm('A', $this->authUser))  {
                $this->jerr("PERMISSION DENIED");
            }
        }
        */
        $cols = $x->table();
         
        if ($old) {
            $this->addEvent("EDIT", $x, $x->toEventString());
            //print_r($x);
            //print_r($old);
            if (isset($cols['modified'])) {
                $x->modified = date('Y-m-d H:i:s');
            }
            if (isset($cols['modified_dt'])) {
                $x->modified_dt = date('Y-m-d H:i:s');
            }
            if (isset($cols['modified_by'])) {
                $x->modified_by = $this->authUser->id;
            }
            
            
            
            $x->update($old);
            if (method_exists($x, 'onUpdate')) {
                $x->onUpdate($old, $_REQUEST, $this);
            }
        } else {
            if (isset($cols['created'])) {
                $x->created = date('Y-m-d H:i:s');
            }
            if (isset($cols['created_dt'])) {
                $x->created_dt = date('Y-m-d H:i:s');
            }
            if (isset($cols['created_by'])) {
                $x->created_by = $this->authUser->id;
            }
            $x->insert();
            if (method_exists($x, 'onInsert')) {
                $x->onInsert($_REQUEST, $this);
            }
            $this->addEvent("ADD", $x, $x->toEventString());
        }
        // note setFrom might handle this before hand...!??!
        if (!empty($_FILES) && method_exists($x, 'onUpload')) {
            $x->onUpload($this);
        }
        
        $r = DB_DataObject::factory($x->tableName());
        $r->id = $x->id;
        $this->loadMap($r, $_columns);
        $r->limit(1);
        $r->find(true);
        
        $rooar = method_exists($r, 'toRooArray');
        //print_r(var_dump($rooar)); exit;
        $this->jok($rooar  ? $r->toRooArray() : $r->toArray() );
        
        
    }
    
    var $cols = array();
    function loadMap($do, $filter) 
    {
        //DB_DataObject::debugLevel(1);
        $conf = array();
        
        
        $this->init();
        $mods = explode(',',$this->appModules);
        
        $ff = HTML_FlexyFramework::geT();
        //$db->databaseName();
        //$ff->DB_DataObject['ini_'. $db->database()];
        echo '<PRE>';print_r($do->database());exit;
        //var_dump($mods);
        
        foreach(in_array('Builder', $mods) ? scandir($this->rootDir.'/Pman') : $mods as $m) {
            
            if (!strlen($m) || $m[0] == '.' || !is_dir($this->rootDir."/Pman/$m")) {
                continue;
            }
            $ini = $this->rootDir."/Pman/$m/DataObjects/pman.links.ini";
            if (!file_exists($ini)) {
                continue;
            }
            $conf = array_merge($conf, parse_ini_file($ini,true));
        }
         
        if (empty($conf)) {
            return;
        }
        $tabdef = $do->table();
        if (isset($tabdef['passwd'])) {
            // prevent exposure of passwords..
            unset($tabdef['passwd']);     
        }
        $xx = clone($do);
        $xx = array_keys($tabdef);
        $do->selectAdd(); // we need thsi as normally it's only cleared by an empty selectAs call.
        
        if ($filter) {
            $cols = array();
         
            foreach($xx as $c) {
                if (in_array($c, $filter)) {
                    $cols[] = $c;
                }
            }
            $do->selectAs($cols);
        } else {
            $do->selectAs($xx);
        }
        
        
        
        
        $this->cols = $xx;
        
        
        if (!isset($conf[$do->tableName()])) {
            return;
        }
        
        $map = $conf[$do->tableName()];
        
        foreach($map as $ocl=>$info) {
            
            list($tab,$col) = explode(':', $info);
            // what about multiple joins on the same table!!!
            $xx = DB_DataObject::factory($tab);
            if (!is_a($xx, 'DB_DataObject')) {
                continue;
            }
            // this is borked ... for multiple jions..
            $do->joinAdd($xx, 'LEFT', 'join_'.$ocl.'_'. $col, $ocl);
            $tabdef = $xx->table();
            $table = $xx->tableName();
            if (isset($tabdef['passwd'])) {
             
                unset($tabdef['passwd']);
              
            } 
            $xx = array_keys($tabdef);
            
            
            if ($filter) {
                $cols = array();
                foreach($xx as $c) {
                    
                    $tn = sprintf($ocl.'_%s', $c);
                    if (in_array($tn, $filter)) {
                        $cols[] = $c;
                    }
                }
                $do->selectAs($cols, $ocl.'_%s', 'join_'.$ocl.'_'. $col);
            } else {
                $do->selectAs($xx,  $ocl.'_%s', 'join_'.$ocl.'_'. $col);
            }
            
             
            
            
            
            foreach($xx as $k) {
                $this->cols[] = sprintf($ocl.'_%s', $k);
            }
            
            
        }
        //DB_DataObject::debugLevel(1);
        
        
        
    }
    function setFilters($x, $q)
    {
        // if a column is type int, and we get ',' -> the it should be come an inc clause..
       // DB_DataObject::debugLevel(1);
        if (method_exists($x, 'applyFilters')) {
           // DB_DataObject::debugLevel(1);
            $x->applyFilters($q, $this->authUser);
        }
        $q_filtered = array();
        
        foreach($q as $key=>$val) {
            
            if (is_array($val)) {
                continue;
            }
            if ($key[0] == '!' && in_array(substr($key, 1), $this->cols)) {
                    
                $x->whereAdd( $x->tableName() .'.' .substr($key, 1) . ' != ' .
                    (is_numeric($val) ? $val : "'".  $x->escape($val) . "'")
                );
                continue;
                
            }
            
            
            
            switch($key) {
                    
                // Events and remarks
                case 'on_id':  // where TF is this used...
                    if (!empty($q['query']['original'])) {
                      //  DB_DataObject::debugLevel(1);
                        $o = (int) $q['query']['original'];
                        $oid = (int) $val;
                        $x->whereAdd("(on_id = $oid  OR 
                                on_id IN ( SELECT distinct(id) FROM Documents WHERE original = $o ) 
                            )");
                        continue;
                                
                    }
                    $x->on_id = $val;
                
                
                default:
                    if (strlen($val)) {
                        $q_filtered[$key] = $val;
                    }
                    
                    continue;
            }
        }
        $x->setFrom($q_filtered);
       
        // nice generic -- let's get rid of it.. where is it used!!!!
        // used by: 
        // Person / Group
        if (!empty($q['query']['name'])) {
            $x->whereAdd($x->tableName().".name LIKE '". $x->escape($q['query']['name']) . "%'");
        }
        
        // - projectdirectory staff list - persn queuy
      
         
       
         
          
        
        
    }
    
}