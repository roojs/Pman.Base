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
 * - applySort($au, $sortcol, $direction, $array_of_columns, $multisort) -- does not support multisort at present..
 * - applyFilters($_REQUEST, $authUser) -- apply any query filters on data. and hide stuff not to be seen.
 * - postListExtra($_REQUEST) : array(extra_data) - add extra column data on the results (like new messages etc.)
 * - postListFilter($data, $authUser, $request) return $data - add extra data to an object
 * 
 * - toRooSingleArray($authUser, $request) // single fetch, add data..
 * - toRooArray($request) /// toArray if you need to return different data.. for a list fetch.
 * 
 * 
 * - beforeDelete($ar) -- return false for fail and set DO->err;
 *                        Argument is an array of un-find/fetched dependant items.
 * - onUpdate($old, $request,$roo) - after update // return value ignored
 * - onInsert($request,$roo) - after insert
 * - onUpload($roo)
 * - setFromRoo($ar) - values from post (deal with dates etc.) - return true|error string.
 * 
 * - toEventString (for logging - this is generically prefixed to all database operations.)
 */

class Pman_Roo extends Pman
{
    
    
    var $key; // used by update currenly to store primary key.
    
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
     * 
     * !colname=....                 => colname != ....
     * !colname[0]=... !colname[1]=... => colname NOT IN (.....) ** only supports main table at present..
     * colname[0]=... colname[1]=... => colname IN (.....) ** only supports main table at present..
     * 
     * other opts:
     * _post      = simulate a post with debuggin on.
     * lookup     =  array( k=>v) single fetch based on a key/value pair
     * _id        =  single fetch based on id.
     * _delete    = delete a list of ids element. (seperated by ,);
     * _columns   = comma seperated list of columns.
     * _distinct   = a distinct column lookup.
     * _requestMeta = default behaviour of Roo stores.. on first query..
     * 
     * csvCols[0] csvCols[1]....    = .... column titles for CSV output
     * 
     * csvTitles[0], csvTitles[1] ....  = columns to use for CSV output
     *
     * sort        = sort column (',' comma delimited)
     * dir         = sort direction ?? in future comma delimited...
     * _multisort  = JSON encoded { sort : { row : direction }, order : [ row, row, row ] }
     * start       = limit start
     * limit       = limit number 
     * 
     * _toggleActive !:!:!:! - this hsould not really be here..
     * query[add_blank] - add a line in with an empty option...  - not really needed???
     * 
     */
    function get($tab)
    {
         //  $this->jerr("Not authenticated", array('authFailure' => true));
       //echo '<PRE>';print_R($_GET);
      //DB_DataObject::debuglevel(1);
        
        $this->init(); // from pnan.
        //DB_DataObject::debuglevel(1);
        HTML_FlexyFramework::get()->generateDataobjectsCache($this->isDev);
        // debugging...
        if (!empty($_GET['_post'])) {
            $_POST  = $_GET;
            //DB_DAtaObject::debuglevel(1);
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
            
            $this->jok(method_exists($x, 'toRooSingleArray') ? $x->toRooSingleArray($this->authUser, $_REQUEST) : $x->toArray());
            
        }
        
       
        if (isset($_REQUEST['_delete'])) {
            
            $keys = $x->keys();
            if (empty($keys) ) {
                $this->jerr('no key');
            }
            
            $this->key = $keys[0];
            
            
            // do we really delete stuff!?!?!?
            return $this->delete($x,$_REQUEST);
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
       //DB_DataObject::debugLevel(1);
        if (method_exists($x, 'checkPerm') && !$x->checkPerm('S', $this->authUser))  {
            $this->jerr("PERMISSION DENIED");
        }
        
        // sets map and countWhat
        $this->loadMap($x, $_columns, empty($_REQUEST['_distinct']) ? false:  $_REQUEST['_distinct']);
        
        $this->setFilters($x,$_REQUEST);
      
         //print_r($x);
        // build join if req.
          //DB_DataObject::debugLevel(1);
        $total = $x->count($this->countWhat);
        // sorting..
      //   
        //var_dump($total);exit;
        $this->applySort($x);
        
        
 
        $x->limit(
            empty($_REQUEST['start']) ? 0 : (int)$_REQUEST['start'],
            min(empty($_REQUEST['limit']) ? 25 : (int)$_REQUEST['limit'], 5000)
        );
        
        $queryObj = clone($x);
        //DB_DataObject::debuglevel(1);
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
        
          
        $rooar = method_exists($x, 'toRooArray');
        
        while ($x->fetch()) {
            //print_R($x);exit;
            $add = $rooar  ? $x->toRooArray($_REQUEST) : $x->toArray();
            
            $ret[] =  !$_columns ? $add : array_intersect_key($add, array_flip($_columns));
        }
        $extra = false;
        if (method_exists($queryObj ,'postListExtra')) {
            $extra = $queryObj->postListExtra($_REQUEST);
        }
        // filter results, and add any data that is needed...
        if (method_exists($x,'postListFilter')) {
            $ret = $x->postListFilter($ret, $this->authUser, $_REQUEST);
        }
        
        if (!empty($_REQUEST['csvCols']) && !empty($_REQUEST['csvTitles']) ) {
            header('Content-type: text/csv');
            
            header('Content-Disposition: attachment; filename="list-export-'.date('Y-m-d') . '.csv"');
            //header('Content-type: text/plain');
            $fh = fopen('php://output', 'w');
            fputcsv($fh, $_REQUEST['csvTitles']);
            
            
            foreach($ret as $x) {
                //echo "<PRE>"; print_r(array($_REQUEST['csvCols'], $x->toArray())); exit;
                $line = array();
                foreach($_REQUEST['csvCols'] as $k) {
                    $line[] = isset($x[$k]) ? $x[$k] : '';
                }
                fputcsv($fh, $line);
            }
            fclose($fh);
            exit;
            
        
        }
        //die("DONE?");
      
        //if ($x->tableName() == 'Documents_Tracking') {
        //    $ret = $this->replaceSubject(&$ret, 'doc_id_subject');
       // }
        
        
        
        if (!empty($_REQUEST['_requestMeta']) &&  count($ret)) {
            $meta = $this->meta($x, $ret);
            if ($meta) {
                $extra['metaData'] = $meta;
            }
        }
        
       // echo "<PRE>"; print_r($ret);
        $this->jdata($ret,$total, $extra );

    
    }
    /**
     * applySort
     * 
     * apply REQUEST[sort] and [dir]
     * sort may be an array of columsn..
     * 
     * @arg   DB_DataObject $x
     * 
     */
    function applySort($x, $sort = '', $dir ='')
    {
        
        // Db_DataObject::debugLevel(1);
        $sort = empty($_REQUEST['sort']) ? $sort : $_REQUEST['sort'];
        $dir = empty($_REQUEST['dir']) ? $dir : $_REQUEST['dir'];
        $dir = $dir == 'ASC' ? 'ASC' : 'DESC';
         
        $ms = empty($_REQUEST['_multisort']) ? false : $_REQUEST['_multisort'];
        $sorted = false;
        if (method_exists($x, 'applySort')) {
            $sorted = $x->applySort(
                    $this->authUser,
                    $sort,
                    $dir,
                    array_keys($this->cols),
                    $ms ? json_decode($ms) : false
            );
        }
        if ($ms) {
            return $this->multiSort($x);
        }
        
        if ($sorted === false) {
            
            $cols = $x->table();
            $sort_ar = explode(',', $sort);
            $sort_str = array();
          
            foreach($sort_ar as $sort) {
                
                if (strlen($sort) && isset($cols[$sort]) ) {
                    $sort_str[] =  $x->tableName() .'.'.$sort . ' ' . $dir ;
                    
                } else if (in_array($sort, array_keys($this->cols))) {
                    $sort_str[] = $sort . ' ' . $dir ;
                }
            }
             
            if ($sort_str) {
                $x->orderBy(implode(', ', $sort_str ));
            }
        }
    }
    
    function multiSort($x)
    {
        //DB_DataObject::debugLevel(1);
        $ms = json_decode($_REQUEST['_multisort']);
        
        $sort_str = array();
        
        $cols = $x->table();
        foreach($ms->order  as $col) {
            if (!isset($ms->sort->{$col})) {
                continue; // no direction..
            }
            $ms->sort->{$col} = $ms->sort->{$col}  == 'ASC' ? 'ASC' : 'DESC';
            
            if (strlen($col) && isset($cols[$col]) ) {
                $sort_str[] =  $x->tableName() .'.'.$col . ' ' .  $ms->sort->{$col};
                
            } else if (in_array($col, array_keys($this->cols))) {
                $sort_str[] = $col. ' ' . $ms->sort->{$col};
            }
        }
         
        if ($sort_str) {
            $x->orderBy(implode(', ', $sort_str ));
        }
         
        
        
    }
    
    
    
     /**
     * POST method   Roo/TABLENAME.php 
     * -- updates the data..
     * 
     * other opts:
     * _debug - forces debugging on.
     * _get - forces a get request
     * _ids - multiple update of records.
     * {colid} - forces fetching
     * 
     */
    function post($tab) // update / insert (?? dleete??)
    {
        //DB_DataObject::debugLevel(1);
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
        
        $this->key = $keys[0];
        
          // delete should be here...
        if (isset($_REQUEST['_delete'])) {
            // do we really delete stuff!?!?!?
            return $this->delete($x,$_REQUEST);
        } 
         
        
        $old = false;
        
        // not sure if this is a good idea here...
        
        if (!empty($_REQUEST['_ids'])) {
            $ids = explode(',',$_REQUEST['_ids']);
            $x->whereAddIn($this->key, $ids, 'int');
            $ar = $x->fetchAll();
            foreach($ar as $x) {
                $this->update($x, $_REQUEST);
                
            }
            // all done..
            $this->jok("UPDATED");
            
            
        }
         
        if (!empty($_REQUEST[$this->key])) {
            // it's a create..
            if (!$x->get($this->key, $_REQUEST[$this->key]))  {
                $this->jerr("Invalid request");
            }
            $this->jok($this->update($x, $_REQUEST));
        } else {
            
            if (empty($_POST)) {
                $this->jerr("No data recieved for inserting");
            }
            
            $this->jok($this->insert($x, $_REQUEST));
            
        }
        
        
        
    }
    function insert($x, $req)
    {
        
    
        if (method_exists($x, 'checkPerm') && !$x->checkPerm('A', $this->authUser, $req))  {
            $this->jerr("PERMISSION DENIED");
        }
        
        $_columns = !empty($req['_columns']) ? explode(',', $req['_columns']) : false;
   
        if (method_exists($x, 'setFromRoo')) {
            $res = $x->setFromRoo($req, $this);
            if (is_string($res)) {
                $this->jerr($res);
            }
        } else {
            $x->setFrom($req);
        }
        
         
        $cols = $x->table();
     
        if (isset($cols['created'])) {
            $x->created = date('Y-m-d H:i:s');
        }
        if (isset($cols['created_dt'])) {
            $x->created_dt = date('Y-m-d H:i:s');
        }
        if (isset($cols['created_by'])) {
            $x->created_by = $this->authUser->id;
        }
        
     
         if (isset($cols['modified'])) {
            $x->modified = date('Y-m-d H:i:s');
        }
        if (isset($cols['modified_dt'])) {
            $x->modified_dt = date('Y-m-d H:i:s');
        }
        if (isset($cols['modified_by'])) {
            $x->modified_by = $this->authUser->id;
        }
        
        if (isset($cols['updated'])) {
            $x->updated = date('Y-m-d H:i:s');
        }
        if (isset($cols['updated_dt'])) {
            $x->updated_dt = date('Y-m-d H:i:s');
        }
        if (isset($cols['updated_by'])) {
            $x->updated_by = $this->authUser->id;
        }
        
     
        
        
        
        $res = $x->insert();
        if ($res === false) {
            $this->jerr($x->_lastError->toString());
        }
        if (method_exists($x, 'onInsert')) {
            $x->onInsert($_REQUEST, $this);
        }
        $ev = $this->addEvent("ADD", $x);
        if ($ev) { 
            $ev->audit($x);
        }
        
        // note setFrom might handle this before hand...!??!
        if (!empty($_FILES) && method_exists($x, 'onUpload')) {
            $x->onUpload($this);
        }
        
        $r = DB_DataObject::factory($x->tableName());
        // let's assume it has a key!!!
        
        $r->{$this->key} = $x->{$this->key};
        $this->loadMap($r, $_columns);
        $r->limit(1);
        $r->find(true);
        
        $rooar = method_exists($r, 'toRooArray');
        //print_r(var_dump($rooar)); exit;
        return $rooar  ? $r->toRooArray($_REQUEST) : $r->toArray();
    }
    
    
    function update($x, $req)
    {
        if (method_exists($x, 'checkPerm') && !$x->checkPerm('E', $this->authUser, $_REQUEST))  {
            $this->jerr("PERMISSION DENIED");
        }
       
        // check any locks..
        // only done if we recieve a lock_id.
        // we are very trusing here.. that someone has not messed around with locks..
        // the object might want to check in their checkPerm - if locking is essential..
        $lock_warning =  false;
        $lock = DB_DataObjecT::factory('Core_locking');
        if (is_a($lock,'DB_DataObject'))  {
                 
            $lock->on_id = $x->{$this->key};
            $lock->on_table= $x->tableName();
            if (!empty($_REQUEST['_lock_id'])) {
                $lock->whereAdd('id != ' . ((int)$_REQUEST['_lock_id']));
            } else {
               $lock->whereAdd('person_id !=' . $this->authUser->id);
            }
            
            $lock->limit(1);
            if ($lock->find(true)) {
                // it's locked by someone else..
               $p = $lock->person();
               $lock_warning =  "Record was locked by " . $p->name . " at " .$lock->created.
                           " - Your changes have been saved - you may like to warn them if " .
                           " they are editing now";
            }
            // check the users lock.. - no point.. ??? - if there are no other locks and it's not the users, then they can 
            // edit it anyways...
            
        }
        
         
        
        
        
       
        $_columns = !empty($req['_columns']) ? explode(',', $req['_columns']) : false;

       
        $old = clone($x);
        $this->old = $x;
        // this lot is generic.. needs moving 
        if (method_exists($x, 'setFromRoo')) {
            $res = $x->setFromRoo($req, $this);
            if (is_string($res)) {
                $this->jerr($res);
            }
        } else {
            $x->setFrom($req);
        }
        $ev = $this->addEvent("EDIT", $x);
        $ev->audit($x, $old);
        //print_r($x);
        //print_r($old);
        
        $cols = $x->table();
        
        if (isset($cols['modified'])) {
            $x->modified = date('Y-m-d H:i:s');
        }
        if (isset($cols['modified_dt'])) {
            $x->modified_dt = date('Y-m-d H:i:s');
        }
        if (isset($cols['modified_by'])) {
            $x->modified_by = $this->authUser->id;
        }
        
        if (isset($cols['updated'])) {
            $x->updated = date('Y-m-d H:i:s');
        }
        if (isset($cols['updated_dt'])) {
            $x->updated_dt = date('Y-m-d H:i:s');
        }
        if (isset($cols['updated_by'])) {
            $x->updated_by = $this->authUser->id;
        }
        
        //DB_DataObject::DebugLevel(1);
        $res = $x->update($old);
        if ($res === false) {
            $this->jerr($x->_lastError->toString());
        }
        
        if (method_exists($x, 'onUpdate')) {
            $x->onUpdate($old, $req, $this);
        }
        
        
        if ($lock_warning) {
            $this->jerr($lock_warning);
        }
        
        
        $r = DB_DataObject::factory($x->tableName());
        // let's assume it has a key!!!
        $r->{$this->key}= $x->{$this->key};
        $this->loadMap($r, $_columns);
        $r->limit(1);
        $r->find(true);
        $rooar = method_exists($r, 'toRooArray');
        //print_r(var_dump($rooar)); exit;
        return $rooar  ? $r->toRooArray($_REQUEST) : $r->toArray();
    }
    /**
     * Delete a number of records.
     * calls $delete_obj->beforeDelete($array_of_dependant_dataobjects)
     *
     */
    
    function delete($x, $req)
    {
        // do we really delete stuff!?!?!?
        if (empty($req['_delete'])) {
            $this->jerr("Delete Requested with no value");
        }
        // build a list of tables to queriy for dependant data..
        $map = $x->links();
        
        $affects  = array();
        
        $all_links = $GLOBALS['_DB_DATAOBJECT']['LINKS'][$x->_database];
        foreach($all_links as $tbl => $links) {
            foreach($links as $col => $totbl_col) {
                $to = explode(':', $totbl_col);
                if ($to[0] != $x->tableName()) {
                    continue;
                }
                
                $affects[$tbl .'.' . $col] = true;
            }
        }
        // collect tables
                die("DELL START");

       // echo '<PRE>';print_r($affects);exit;
       //DB_Dataobject::debugLevel(1);
       
        
        $clean = create_function('$v', 'return (int)$v;');
        
        $bits = array_map($clean, explode(',', $req['_delete']));
        
       // print_r($bits);exit;
         
        // let's assume it has a key!!!
        
        
        $x->whereAdd($this->key .'  IN ('. implode(',', $bits) .')');
        if (!$x->find()) {
            $this->jerr("Nothing found to delete");
        }
        $errs = array();
        while ($x->fetch()) {
            $xx = clone($x);
            
           
            // perms first.
            
            if (method_exists($x, 'checkPerm') && !$x->checkPerm('D', $this->authUser))  {
                $this->jerr("PERMISSION DENIED");
            }
            
            $match_ar = array();
            foreach($affects as $k=> $true) {
                $ka = explode('.', $k);
                $chk = DB_DataObject::factory($ka[0]);
                if (!is_a($chk,'DB_DataObject')) {
                    $this->jerr('Unable to load referenced table, check the links config: ' .$ka[0]);
                }
                $chk->{$ka[1]} =  $xx->{$this->key};
                $matches = $chk->count();
                
                if ($matches) {
                    $match_ar[] = clone($chk);
                    continue;
                }          
            }
            
            $has_beforeDelete = method_exists($xx, 'beforeDelete');
            // before delte = allows us to trash dependancies if needed..
            $match_total = 0;
            
            if ( method_exists($xx, 'beforeDelete') ) {
                if ($xx->beforeDelete($match_ar) === false) {
                    $errs[] = "Delete failed ({$xx->id})\n".
                        (isset($xx->err) ? $xx->err : '');
                    continue;
                }
                // refetch affects..
                
                $match_ar = array();
                foreach($affects as $k=> $true) {
                    $ka = explode('.', $k);
                    $chk = DB_DataObject::factory($ka[0]);
                    if (!is_a($chk,'DB_DataObject')) {
                        $this->jerr('Unable to load referenced table, check the links config: ' .$ka[0]);
                    }
                    $chk->{$ka[1]} =  $xx->$pk;
                    $matches = $chk->count();
                    $match_total += $matches;
                    if ($matches) {
                        $match_ar[] = clone($chk);
                        continue;
                    }          
                }
                
            }
            
            //
            
            
            if (!empty($match_ar)) {
                $chk = $match_ar[0];
                $chk->limit(1);
                $o = $chk->fetchAll();
                $key = $this->key;
                $desc =  $chk->tableName(). '.' . $key .'='.$xx->$key ;
                if (method_exists($chk, 'toEventString')) {
                    $desc .=  ' : ' . $o[0]->toEventString();
                }
                    
                $this->jerr("Delete Dependant records ($match_total  found),  " .
                             "first is ( $desc )");
          
            }
            
            // now che 
            // finally log it.. 
            
            $this->addEvent("DELETE", $x);
            
            $xx->delete();
        }
        if ($errs) {
            $this->jerr(implode("\n<BR>", $errs));
        }
        $this->jok("Deleted");
        
    }
   
    
    
    
    var $cols = array();
    function loadMap($do, $filter=false, $distinct = false) 
    {
        //DB_DataObject::debugLevel(1);
        
        $this->countWhat = false;
        
        $conf = array();
        
        $this->init();
        
        $mods = explode(',',$this->appModules);
        
        $ff = HTML_FlexyFramework::get();
       
         $map = $do->links();
         
        
        
        // current table..
        $tabdef = $do->table();
        if (isset($tabdef['passwd'])) {
            // prevent exposure of passwords..
            unset($tabdef['passwd']);     
        }
        $xx = clone($do);
        $xx = array_keys($tabdef);
        $do->selectAdd(); // we need thsi as normally it's only cleared by an empty selectAs call.
        
        $selectAs = array(array(  $xx , '%s', false));
       
        $has_distinct = false;
        if ($filter || $distinct) {
            $cols = array();
            //echo '<PRE>' ;print_r($filter);exit;
            foreach($xx as $c) {
                if ($distinct && $distinct == $c) {
                    $has_distinct = 'DISTINCT( ' . $do->tableName() .'.'. $c .') as ' . $c;
                    $this->countWhat =  'DISTINCT  ' . $do->tableName() .'.'. $c .'';
                    continue;
                }
                if (!$filter || in_array($c, $filter)) {
                    $cols[] = $c;
                }
            }
            
            
            $selectAs = empty($cols) ?  array() : array(array(  $cols , '%s', false)) ;
            
            
            
        } 
        
        $this->cols = array();
        $this->colsJoinName =array();
        foreach($xx as $k) {
            $this->cols[$k] = $do->tableName(). '.' . $k;
        }
        
        
        
        
         
        
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
            
            
            if ($filter || $distinct) {
                $cols = array();
                foreach($xx as $c) {
                    $tn = sprintf($ocl.'_%s', $c);
                  // echo '<PRE>'; var_dump($tn);
                    if ($distinct && $tn == $distinct) {
                        $has_distinct = 'DISTINCT( ' . 'join_'.$ocl.'_'.$col.'.'.$k .')  as ' . $tn ;
                        $this->countWhat =  'DISTINCT  join_'.$ocl.'_'.$col.'.'.$k;
                        continue;
                    }
                    
                    
                    if (!$filter || in_array($tn, $filter)) {
                        $cols[] = $c;
                    }
                }
                if (!empty($cols)) {
                     $selectAs[] = array($cols, $ocl.'_%s', 'join_'.$ocl.'_'. $col);
                }
               
                
            } else {
                $selectAs[] = array($xx, $ocl.'_%s', 'join_'.$ocl.'_'. $col);
                
            }
             
            
            foreach($xx as $k) {
                $this->cols[sprintf($ocl.'_%s', $k)] = $tab.'.'.$k;
                $this->colsJname[sprintf($ocl.'_%s', $k)] = 'join_'.$ocl.'_'.$col.'.'.$k;
            }
            
            
        }
        
        if ($has_distinct) {
            $do->selectAdd($has_distinct);
        }
         
        // we do select as after everything else as we need to plop distinct at the beginning??
        /// well I assume..
       // echo '<PRE>';print_r($this->colsJname);exit;
        foreach($selectAs as $ar) {
            $do->selectAs($ar[0], $ar[1], $ar[2]);
        }
        
        
    }
    /**
     * generate the meta data neede by queries.
     * 
     */
    function meta($x, $data)
    {
        // this is not going to work on queries where the data does not match the database def..
        // for unknown columns we send them as stirngs..
        $lost = 0;
        $cols  = array_keys($data[0]);
     
        
        
        
        //echo '<PRE>';print_r($this->cols); exit;
        $options = &PEAR::getStaticProperty('DB_DataObject','options');
        $reader = $options["ini_{$x->_database}"] .'.reader';
        if (!file_exists( $reader )) {
            return;
        }
        
        $rdata = unserialize(file_get_contents($reader));
        
       // echo '<PRE>';print_r($rdata);exit;
        
        $meta = array();
        foreach($cols as $c ) {
            if (!isset($this->cols[$c]) || !isset($rdata[$this->cols[$c]]) || !is_array($rdata[$this->cols[$c]])) {
                $meta[] = $c;
                continue;    
            }
            $add = $rdata[$this->cols[$c]];
            $add['name'] = $c;
            $meta[] = $add;
        }
        return array(
            'totalProperty' =>  'total',
            'successProperty' => 'success',
            'root' => 'data',
            'id' => 'id',
            'fields' => $meta
        );
         
        
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
            
            // value is an array..
            if (is_array($val) ) {
                
                $pref = '';
                
                if ($key[0] == '!') {
                    $pref = '!';
                    $key = substr($key,1);
                }
                
                if (!in_array( $key,  array_keys($this->cols))) {
                    continue;
                }
                
                // support a[0] a[1] ..... => whereAddIn(
                $ar = array();
                $quote = false;
                foreach($val as $k=>$v) {
                    if (!is_numeric($k)) {
                        $ar = array();
                        break;
                    }
                    // FIXME: note this is not typesafe for anything other than mysql..
                    
                    if (!is_numeric($v) || !is_long($v)) {
                        $quote = true;
                    }
                    $ar[] = $v;
                    
                }
                if (count($ar)) {
                    
                    
                    $x->whereAddIn($pref . (
                        isset($this->colsJname[$key]) ? 
                            $this->colsJname[$key] :
                            ($x->tableName(). '.'.$key)),
                        $ar, $quote ? 'string' : 'int');
                }
                
                continue;
            }
            
            
            
            if ($key[0] == '!' && in_array(substr($key, 1), array_keys($this->cols))) {
                
                $key  = substr($key, 1) ;
                
                $x->whereAdd(   (
                        isset($this->colsJname[$key]) ? 
                            $this->colsJname[$key] :
                            $x->tableName(). '.'.$key ) . ' != ' .
                    (is_numeric($val) ? $val : "'".  $x->escape($val) . "'")
                );
                continue;
                
            }
            
            
            
            switch($key) {
                    
                // Events and remarks -- fixme - move to events/remarsk...
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
                    
                    // subjoined columns = check the values.
                    // note this is not typesafe for anything other than mysql..
                    
                    if (isset($this->colsJname[$key])) {
                        $quote = false;
                        if (!is_numeric($val) || !is_long($val)) {
                            $quote = true;
                        }
                        $x->whereAdd( "{$this->colsJname[$key]} = " . ($quote ? "'". $x->escape($val) ."'" : $val));
                        
                    }
                    
                    
                    continue;
            }
        }
        
        $x->setFrom($q_filtered);
        
        
        
       
        // nice generic -- let's get rid of it.. where is it used!!!!
        // used by: 
        // Person / Group / most of my queries noww...
        if (!empty($q['query']['name'])) {
            $x->whereAdd($x->tableName().".name LIKE '". $x->escape($q['query']['name']) . "%'");
        }
        
        // - projectdirectory staff list - persn queuy
      
         
       
         
          
        
        
    }
    
}