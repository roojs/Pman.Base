<?php


require_once 'Pman.php';
/**
 * 
 * 
  * 
 * 
 * Uses these methods of the dataobjects:
 * 
 * - checkPerm('L'/'E'/'A', $authuser) - can we list the stuff
 * 
 * - applySort($au, $sortcol, $direction, $array_of_columns, $multisort) -- does not support multisort at present..
 * - applyFilters($_REQUEST, $authUser, $roo) -- apply any query filters on data. and hide stuff not to be seen. (RETURN false to prevent default filters.)
 * - postListExtra($_REQUEST) : array(extra_name => data) - add extra column data on the results (like new messages etc.)
 * - postListFilter($data, $authUser, $request) return $data - add extra data to an object
 * 
 * - toRooSingleArray($authUser, $request) // single fetch, add data..
 * - toRooArray($request) /// toArray if you need to return different data.. for a list fetch.
 *
 * 
 *  CRUD - before/after handlers..
 * - setFromRoo($ar, $roo) - values from post (deal with dates etc.) - return true|error string.
 *      ... call $roo->jerr() on failure...
 *
 *  BEFORE
 * - beforeDelete($dependants_array, $roo) Argument is an array of un-find/fetched dependant items.
 *                      - jerr() will stop insert.. (Prefered)
 *                      - return false for fail and set DO->err;
 * - beforeUpdate($old, $request,$roo) - after update - jerr() will stop insert..
 * - beforeInsert($request,$roo) - before insert - jerr() will stop insert..
 *
 *  AFTER
 * - onUpdate($old, $request,$roo) - after update // return value ignored
 * - onInsert($request,$roo) - after insert
 * - onDelete($req, $roo) - after delete
 * - onUpload($roo)
 * 
 
 * 
 * - toEventString (for logging - this is generically prefixed to all database operations.)
 */

class Pman_Roo extends Pman
{
    /**
     * if set to an array (when extending this, then you can restrict which tables are available
     */
    var $validTables = false; 
    
    var $key; // used by update currenly to store primary key.
    
    var $transObj = false ; // the transaction BEGIN / ROLLBACK / COMMIT Dataobject.
    
    
    var $debugEnabled = true; // disable this for public versions of this code.
    
    function getAuth()
    {
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
     *
     * Generally for SELECT or Single SELECT
     *
     * Single SELECT:
     *    _id=value          single fetch based on primary id.
     *                       can be '0' if you want to fetch a set of defaults
     *                       Use in conjuntion with toRooSingleArray()
     *                      
     *    lookup[key]=value  single fetch based on a single key value lookup.
     *                       multiple key/value can be used. eg. ontable+onid..
     *    _columns           what to return.
     *
     *    
     * JOINS:
     *  - all tables are always autojoined.
     * 
     * Search SELECT
     *    COLUMNS to fetch
     *      _columns=a,b,c,d     comma seperated list of columns.
     *      _columns_exclude=a,b,c,d   comma seperated list of columns.
     *      _distinct=name        a distinct column lookup. you also have to use _columns with this.
     *
     *    WHERE (searches)
     *       colname = ...              => colname = ....
     *       !colname=....                 => colname != ....
     *       !colname[0]=... !colname[1]=... => colname NOT IN (.....) ** only supports main table at present..
     *       colname[0]=... colname[1]=... => colname IN (.....) ** only supports main table at present..
     *
     *    ORDER BY
     *       sort=name          what to sort.
     *       sort=a,b,d         can support multiple columns
     *       dir=ASC            what direction
     *       _multisort ={...}  JSON encoded { sort : { row : direction }, order : [ row, row, row ] }
     *
     *    LIMIT
     *      start=0         limit start
     *      limit=25        limit number 
     * 
     * 
     *    Simple CSV support
     *      csvCols[0] csvCols[1]....    = .... column titles for CSV output
     *      csvTitles[0], csvTitles[1] ....  = columns to use for CSV output
     *
     *  Depricated  
     *      _toggleActive !:!:!:! - this hsould not really be here..
     *      query[add_blank] - add a line in with an empty option...  - not really needed???
     *      _delete    = delete a list of ids element. (depricated.. this will be removed...)
     * 
     * DEBUGGING
     *  _post   =1    = simulate a post with debuggin on.
     * 
     *  _debug     = turn on DB_dataobject deubbing, must be admin at present..
     *
     *
     * CALLS methods on dataobjects if they exist
     *
     * 
     *   checkPerm('S' , $authuser)
     *                      - can we list the stuff
     *                      - return false to disallow...
     *   applySort($au, $sortcol, $direction, $array_of_columns, $multisort)
     *                     -- does not support multisort at present..
     *   applyFilters($_REQUEST, $authUser, $roo)
     *                     -- apply any query filters on data. and hide stuff not to be seen.
     *                     -- can exit by calling $roo->jerr()
     *   postListExtra($_REQUEST) : array(extra_name => data)
     *                     - add extra column data on the results (like new messages etc.)
     *   postListFilter($data, $authUser, $request) return $data
     *                      - add extra data to an object
     * 
     *   
     *   toRooSingleArray($authUser, $request) : array
     *                       - called on single fetch only, add or maniuplate returned array data.
     *                       - is also called when _id=0 is used (for fetching a default set.)
     *   toRooArray($request) : array
     *                      - called if singleArray is unavailable on single fetch.
     *                      - always tried for mutiple results.
     *   toArray()          - the default method if none of the others are found. 
     *   
     *   autoJoin($request) 
     *                      - standard DataObject feature - causes all results to show all
     *                        referenced data.
     *
     * PROPERTIES:
     *    _extra_cols  -- if set, then filtering by column etc. will use them.
     *
     
     */
    function get($tab)
    {
         //  $this->jerr("Not authenticated", array('authFailure' => true));
       //echo '<PRE>';print_R($_GET);
      //DB_DataObject::debuglevel(1);
        
        $this->init(); // from pman.
        //DB_DataObject::debuglevel(1);
        HTML_FlexyFramework::get()->generateDataobjectsCache($this->isDev);
        
   
        
        // debugging...
        
        
        
        if ( $this->checkDebugPost()) {
                    
            
            
            $_POST  = $_GET;
            //DB_DAtaObject::debuglevel(1);
            return $this->post($tab);
        }
        
        $this->checkDebug();
        
        PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, array($this, 'onPearError'));
   
         
        $x = $this->dataObject($tab);
        
        $_columns = !empty($_REQUEST['_columns']) ? explode(',', $_REQUEST['_columns']) : false;
        
        if (isset( $_REQUEST['lookup'] ) && is_array($_REQUEST['lookup'] )) { // single fetch based on key/value pairs
             $this->selectSingle($x, $_REQUEST['lookup'],$_REQUEST);
             // actually exits.
        }
        
        
        // single fetch (use '0' to fetch an empty object..)
        if (isset($_REQUEST['_id']) && is_numeric($_REQUEST['_id'])) {
             
             $this->selectSingle($x, $_REQUEST['_id'],$_REQUEST);
             // actually exits.
        }
        
        // Depricated...

       
        if (isset($_REQUEST['_delete'])) {
            $this->jerr("DELETE by GET has been removed - update the code to use POST");
            /*
            
            $keys = $x->keys();
            if (empty($keys) ) {
                $this->jerr('no key');
            }
            
            $this->key = $keys[0];
            
            
            // do we really delete stuff!?!?!?
            return $this->delete($x,$_REQUEST);
            */
        } 
        
        
        // Depricated...
        
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
       
        
        // sets map and countWhat
        $this->loadMap($x, array(
                    'columns' => $_columns,
                    'distinct' => empty($_REQUEST['_distinct']) ? false:  $_REQUEST['_distinct'],
                    'exclude' => empty($_REQUEST['_exclude_columns']) ? false:  explode(',', $_REQUEST['_exclude_columns'])
            ));
        
        
        $this->setFilters($x,$_REQUEST);
      
        if (method_exists($x, 'checkPerm') && !$x->checkPerm('S', $this->authUser))  {
            $this->jerr("PERMISSION DENIED");
        }
        
         //print_r($x);
        // build join if req.
          //DB_DataObject::debugLevel(1);
       //   var_dump($this->countWhat);
        $total = $x->count($this->countWhat);
        // sorting..
      //   
        //var_dump($total);exit;
        $this->applySort($x);
        
        $fake_limit = false;
        
        if (!empty($_REQUEST['_distinct']) && $total < 400) {
            $fake_limit  = true;
        }
        
        if (!$fake_limit) {
 
            $x->limit(
                empty($_REQUEST['start']) ? 0 : (int)$_REQUEST['start'],
                min(empty($_REQUEST['limit']) ? 25 : (int)$_REQUEST['limit'], 10000)
            );
        } 
        $queryObj = clone($x);
        //DB_DataObject::debuglevel(1);
        
        $this->sessionState(0);
        $res = $x->find();
        $this->sessionState(1);
        
        if (false === $res) {
            $this->jerr($x->_lastError->toString());
            
        }
        
        
        
        $ret = array();
        
        // ---------------- THESE ARE DEPRICATED.. they should be moved to the model...
        
        
        if (!empty($_REQUEST['query']['add_blank'])) {
            $ret[] = array( 'id' => 0, 'name' => '----');
            $total+=1;
        }
         
        $rooar = method_exists($x, 'toRooArray');
        $_columnsf = $_columns  ? array_flip($_columns) : false;
        while ($x->fetch()) {
            //print_R($x);exit;
            $add = $rooar  ? $x->toRooArray($_REQUEST) : $x->toArray();
            
            $ret[] =  !$_columns ? $add : array_intersect_key($add, $_columnsf);
        }
        
        if ($fake_limit) {
            $ret = array_slice($ret,
                   empty($_REQUEST['start']) ? 0 : (int)$_REQUEST['start'],
                    min(empty($_REQUEST['limit']) ? 25 : (int)$_REQUEST['limit'], 10000)
            );
            
        }
        
        
        $extra = false;
        if (method_exists($queryObj ,'postListExtra')) {
            $extra = $queryObj->postListExtra($_REQUEST, $this);
        }
        
        
        // filter results, and add any data that is needed...
        if (method_exists($x,'postListFilter')) {
            $ret = $x->postListFilter($ret, $this->authUser, $_REQUEST);
        }
        
        
        
        if (!empty($_REQUEST['csvCols']) && !empty($_REQUEST['csvTitles']) ) {
            
            
            $this->toCsv($ret, $_REQUEST['csvCols'], $_REQUEST['csvTitles'],
                        empty($_REQUEST['csvFilename']) ? '' : $_REQUEST['csvFilename']
                         );
            
            
        
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
        // this make take some time...
        $this->sessionState(0);
       // echo "<PRE>"; print_r($ret);
        $this->jdata($ret, max(count($ret), $total), $extra );

    
    }
    function checkDebug()
    {
         if (isset($_REQUEST['_debug']) 
                && 
                $this->authUser
                &&
                method_exists($this->authUser,'groups') 
                &&
                is_a($this->authUser, 'Pman_Core_DataObjects_Person')
                &&
                in_array('Administrators', $this->authUser->groups('name'))
                
            ){
            DB_DAtaObject::debuglevel((int)$_REQUEST['_debug']);
        }
        
    }
    
    function checkDebugPost()
    {
         return !empty($_GET['_post']) && 
                    $this->authUser && 
                    method_exists($this->authUser,'groups') &&
                    in_array('Administrators', $this->authUser->groups('name')); 
        
    }
    
    
    function toCsv($data, $cols, $titles, $filename, $addDate = true)
    {
          
        $this->sessionState(0); // turn off sessions  - no locking..

        require_once 'Pman/Core/SimpleExcel.php';
        
        $fn = (empty($filename) ? 'list-export-' : urlencode($filename)) . (($addDate) ? date('Y-m-d') : '') ;
        
        
        $se_config=  array(
            'workbook' => substr($fn, 0, 31),
            'cols' => array(),
            'leave_open' => true
        );
        
        
        $se = false;
        if (is_object($data)) {
            $rooar = method_exists($data, 'toRooArray');
            while($data->fetch()) {
                $x = $rooar  ? $data->toRooArray($q) : $data->toArray();
                
                
                if ($cols == '*') {  /// did we get cols sent to us?
                    $cols = array_keys($x);
                }
                if ($titles== '*') {
                    $titles= array_keys($x);
                }
                if ($titles !== false) {
                    
                    foreach($cols as $i=>$col) {
                        $se_config['cols'][] = array(
                            'header'=> isset($titles[$i]) ? $titles[$i] : $col,
                            'dataIndex'=> $col,
                            'width'=>  100,
                           //     'renderer' => array($this, 'getThumb'),
                             //   'color' => 'yellow', // set color for the cell which is a header element
                              // 'fillBlank' => 'gray', // set 
                        );
                         $se = new Pman_Core_SimpleExcel(array(), $se_config);
       
                        
                    }
                    
                    
                    //fputcsv($fh, $titles);
                    $titles = false;
                }
                

                $se->addLine($se_config['workbook'], $x);
                    
                
            }
            if(!$se){
                
                $this->jerr('no data found', false, 'text/plain');
            }
            $se->send($fn .'.xls');
            exit;
            
        } 
        
        
        foreach($data as $x) {
            //echo "<PRE>"; print_r(array($_REQUEST['csvCols'], $x->toArray())); exit;
            $line = array();
            if ($titles== '*') {
                $titles= array_keys($x);
            }
            if ($cols== '*') {
                $cols= array_keys($x);
            }
            if ($titles !== false) {
                foreach($cols as $i=>$col) {
                    $se_config['cols'][] = array(
                        'header'=> isset($titles[$i]) ? $titles[$i] : $col,
                        'dataIndex'=> $col,
                        'width'=>  100,
                       //     'renderer' => array($this, 'getThumb'),
                         //   'color' => 'yellow', // set color for the cell which is a header element
                          // 'fillBlank' => 'gray', // set 
                    );
                    $se = new Pman_Core_SimpleExcel(array(),$se_config);
   
                    
                }
                
                
                //fputcsv($fh, $titles);
                $titles = false;
            }
            
            
            
            $se->addLine($se_config['workbook'], $x);
        }
        if(!$se){
            $this->jerr('no data found');
        }
        $se->send($fn .'.xls');
        exit;
    
        
        
    }
    
    
     /**
     * POST method   Roo/TABLENAME  
     * -- creates, updates, or deletes data.
     *
     * INSERT
     *    if the primary key is empty, this happens
     *    will automatically set these to current date and authUser->id
     *        created, created_by, created_dt
     *        updated, update_by, updated_dt
     *        modified, modified_by, modified_dt
     *        
     *   will return a GET request SINGLE SELECT (and accepts same)
     *    
     * DELETE
     *    _delete=1,2,3     delete a set of data.
     * UPDATE
     *    if the primary key value is set, then update occurs.
     *    will automatically set these to current date and authUser->id
     *        updated, update_by, updated_dt
     *        modified, modified_by, modified_dt
     *        
     *
     * Params:
     *   _delete=1,2,3   causes a delete to occur.
     *   _ids=1,2,3,4    causes update to occur on all primary ids.
     *  
     *  RETURNS
     *     = same as single SELECT GET request..
     *
     *
     *
     * DEBUGGING
     *   _debug=1    forces debug
     *   _get=1 - causes a get request to occur when doing a POST..
     *
     *
     * CALLS
     *   these methods on dataobjects if they exist
     * 
     *   checkPerm('E' / 'D' , $authuser)
     *                      - can we list the stuff
     *                      - return false to disallow...
   
     *   toRooSingleArray($authUser, $request) : array
     *                       - called on single fetch only, add or maniuplate returned array data.
     *   toRooArray($request) : array
     *                      - Called if toSingleArray does not exist.
     *                      - if you need to return different data than toArray..
     *
     *   toEventString()
     *                  (for logging - this is generically prefixed to all database operations.)
     *
     *  
     *   onUpload($roo)
     *                  called when $_FILES is not empty
     *
     *                  
     *   setFromRoo($ar, $roo)
     *                      - alternative to setFrom() which is called if this method does not exist
     *                      - values from post (deal with dates etc.) - return true|error string.
     *                      - call $roo->jerr() on failure...
     *
     * CALLS BEFORE change occurs:
     *  
     *      beforeDelete($dependants_array, $roo)
     *                      Argument is an array of un-find/fetched dependant items.
     *                      - jerr() will stop insert.. (Prefered)
     *                      - return false for fail and set DO->err;
     *                      
     *      beforeUpdate($old, $request,$roo)
     *                      - after update - jerr() will stop insert..
     *      beforeInsert($request,$roo)
     *                      - before insert - jerr() will stop insert..
     *
     *
     * CALLS AFTER change occured
     * 
     *      onUpdate($old, $request,$roo)
     *               - after update // return value ignored
     *
     *      onInsert($request,$roo)
     *                  - after insert
     * 
     *      onDelete($request, $roo) - after delete
     * 
     */                     
     
    function post($tab) // update / insert (?? delete??)
    {
        
        DB_DAtaObject::debugLevel(1);
        PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, array($this, 'onPearError'));
   
        
        //DB_DataObject::debugLevel(1);
        $this->checkDebug();
        
        if (!empty($_REQUEST['_get'])) {
            return $this->get($tab);
        }
        
        $this->init(); // for pman.
         
        $x = $this->dataObject($tab);
        
        $this->transObj = clone($x);
        
        $this->transObj->query('BEGIN');
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
        //var_Dump($ms);exit;
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
        if ($ms !== false) {
            return $this->multiSort($x);
        }
        
        if ($sorted === false) {
            
            $cols = $x->table();
            $excols = array_keys($this->cols);
            
            if (isset($x->_extra_cols)) {
                $excols = array_merge($excols, $x->_extra_cols);
            }
            $sort_ar = explode(',', $sort);
            $sort_str = array();
          
            foreach($sort_ar as $sort) {
                
                if (strlen($sort) && isset($cols[$sort]) ) {
                    $sort_str[] =  $x->tableName() .'.'.$sort . ' ' . $dir ;
                    
                } else if (in_array($sort, $excols)) {
                    $sort_str[] = $sort . ' ' . $dir ;
                }
            }
             
            if ($sort_str) {
                $x->orderBy(implode(', ', $sort_str ));
            }
        }
    }
    /**
     * Multisort support
     *
     * _multisort
     *
     *
     */
    function multiSort($x)
    {
        //DB_DataObject::debugLevel(1);
        $ms = json_decode($_REQUEST['_multisort']);
        if (!isset($ms->order) || !is_array($ms->order)) {
            return;
        }
        $sort_str = array();
        
        $cols = $x->table();
        
        //print_r($this->cols);exit;
        // this-><cols contains  colname => aliased name...
        foreach($ms->order  as $col) {
            if (!isset($ms->sort->{$col})) {
                continue; // no direction..
            }
            $ms->sort->{$col} = $ms->sort->{$col}  == 'ASC' ? 'ASC' : 'DESC';
            
            if (strlen($col) && isset($cols[$col]) ) {
                $sort_str[] =  $x->tableName() .'.'.$col . ' ' .  $ms->sort->{$col};
                continue;
            }
            //print_r($this->cols);
            
            if (in_array($col, array_keys($this->cols))) {
                $sort_str[] = $col. ' ' . $ms->sort->{$col};
                continue;
            }
            if (isset($x->_extra_cols) && in_array($col, $x->_extra_cols)) {
                $sort_str[] = $col. ' ' . $ms->sort->{$col};
            }
        }
         
        if ($sort_str) {
            $x->orderBy(implode(', ', $sort_str ));
        }
          
        
    }
    /**
     * single select call
     * - used when _id is set, or after insert or update
     *
     * @param DataObject $x the dataobject to use
     * @param int $id       the pid of the object
     * @param array $req    the request, or false if it comes from insert/update.
     *
     */
    function selectSingle($x, $id, $req=false)
    {
         
        
        $_columns = !empty($req['_columns']) ? explode(',', $req['_columns']) : false;
        //var_dump(array(!is_array($id) , empty($id)));
        if (!is_array($id) && empty($id)) {
            
            
            if (method_exists($x, 'toRooSingleArray')) {
                $this->jok($x->toRooSingleArray($this->authUser, $req));
            }
            if (method_exists($x, 'toRooArray')) {
                $this->jok($x->toRooArray($req));
            }
            
            $this->jok($x->toArray());
        }
       
        
        $this->loadMap($x, array(
                    'columns' => $_columns,
                     
            ));
        if ($req !== false) { 
            $this->setFilters($x, $req);
        }
        
        // DB_DataObject::DebugLevel(1);
        if (is_array($id)) {
            // lookup...
            $x->setFrom($req['lookup'] );
            $x->limit(1);
            if (!$x->find(true)) {
                if (!empty($id['_id'])) {
                    // standardize this?
                    $this->jok($x->toArray());
                }
                $this->jok(false);
            }
            
        } else if (!$x->get($id)) {
            $this->jerr("selectSingle: no such record ($id)");
        }
        // ignore perms if comming from update/insert - as it's already done...
        if ($req !== false && method_exists($x, 'checkPerm') && !$x->checkPerm('S', $this->authUser))  {
            $this->jerr("PERMISSION DENIED - si");
        }
        // different symantics on all these calls??
        if (method_exists($x, 'toRooSingleArray')) {
            $this->jok($x->toRooSingleArray($this->authUser, $req));
        }
        if (method_exists($x, 'toRooArray')) {
            $this->jok($x->toRooArray($req));
        }
        
        $this->jok($x->toArray());
        
        
    }
    
    function insert($x, $req, $with_perm_check = true)
    {
        
         if (method_exists($x, 'setFromRoo')) {
            $res = $x->setFromRoo($req, $this);
            if (is_string($res)) {
                $this->jerr($res);
            }
        } else {
            $x->setFrom($req);
        }
        
        if ( $with_perm_check && method_exists($x, 'checkPerm') && !$x->checkPerm('A', $this->authUser, $req))  {
            $this->jerr("PERMISSION DENIED");
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
        
     
        if (method_exists($x, 'beforeInsert')) {
            $x->beforeInsert($_REQUEST, $this);
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
            $x->onUpload($this, $_REQUEST);
        }
        
        return $this->selectSingle(
            DB_DataObject::factory($x->tableName()),
            $x->pid()
        );
        
    }
    
    function updateLock($x, $req )
    {
        Pman::$permitError = true; // allow it to fail without dieing
        
        $lock = DB_DataObjecT::factory('Core_locking');
        Pman::$permitError = false; 
        if (is_a($lock,'DB_DataObject') && $this->authUser)  {
                 
            $lock->on_id = $x->{$this->key};
            $lock->on_table= strtolower($x->tableName());
            if (!empty($_REQUEST['_lock_id'])) {
                $lock->whereAdd('id != ' . ((int)$_REQUEST['_lock_id']));
            } else {
                $lock->whereAdd('person_id !=' . $this->authUser->id);
            }
            
            $lock->limit(1);
            if ($lock->find(true)) {
                // it's locked by someone else..
               $p = $lock->person();
               
               
               $this->jerr( "Record was locked by " . $p->name . " at " .$lock->created.
                           " - Please confirm you wish to save" 
                           , array('needs_confirm' => true)); 
          
              
            }
            // check the users lock.. - no point.. ??? - if there are no other locks and it's not the users, then they can 
            // edit it anyways...
            
            // can we find the user's lock.
            $lock = DB_DataObjecT::factory('Core_locking');
            $lock->on_id = $x->{$this->key};
            $lock->on_table= strtolower($x->tableName());
            $lock->person_id = $this->authUser->id;
            $lock->orderBy('created DESC');
            $lock->limit(1);
            
            if (
                    $lock->find(true) &&
                    isset($x->modified_dt) &&
                    strtotime($x->modified_dt) > strtotime($lock->created) &&
                    empty($req['_submit_confirmed']) &&
	            $x->modified_by != $this->authUser->id 	
                )
            {
                $p = DB_DataObject::factory('Person');
                $p->get($x->modified_by);
		 $this->jerr($p->name . " saved the record since you started editing,\nDo you really want to update it?", array('needs_confirm' => true)); 
                
            }
            
            
            
        }
        return $lock;
        
    }
    
    
    function update($x, $req,  $with_perm_check = true)
    {
        if ( $with_perm_check && method_exists($x, 'checkPerm') && !$x->checkPerm('E', $this->authUser, $_REQUEST))  {
            $this->jerr("PERMISSION DENIED - No Edit permissions on this element");
        }
       
        // check any locks..
        // only done if we recieve a lock_id.
        // we are very trusing here.. that someone has not messed around with locks..
        // the object might want to check in their checkPerm - if locking is essential..
        $lock = $this->updateLock($x,$req);
         
        
        
        
       
         
       
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
      
        
        
        //echo '<PRE>';print_r($old);print_r($x);exit;
        //print_r($old);
        
        $cols = $x->table();
        
        if (isset($cols['modified'])) {
            $x->modified = date('Y-m-d H:i:s');
        }
        if (isset($cols['modified_dt'])) {
            $x->modified_dt = date('Y-m-d H:i:s');
        }
        if (isset($cols['modified_by']) && $this->authUser) {
            $x->modified_by = $this->authUser->id;
        }
        
        if (isset($cols['updated'])) {
            $x->updated = date('Y-m-d H:i:s');
        }
        if (isset($cols['updated_dt'])) {
            $x->updated_dt = date('Y-m-d H:i:s');
        }
        if (isset($cols['updated_by']) && $this->authUser) {
            $x->updated_by = $this->authUser->id;
        }
        
        if (method_exists($x, 'beforeUpdate')) {
            $x->beforeUpdate($old, $req, $this);
        }
        
        if ($with_perm_check && !empty($_FILES) && method_exists($x, 'onUpload')) {
            $x->onUpload($this, $_REQUEST);
        }
        
        //DB_DataObject::DebugLevel(1);
        $res = $x->update($old);
        if ($res === false) {
            $this->jerr($x->_lastError->toString());
        }
        
        if (method_exists($x, 'onUpdate')) {
            $x->onUpdate($old, $req, $this);
        }
        $ev = $this->addEvent("EDIT", $x);
        if ($ev) { 
            $ev->audit($x, $old);
        }
        
        
        return $this->selectSingle(
            DB_DataObject::factory($x->tableName()),
            $x->{$this->key}
        );
        
    }
    /**
     * Delete a number of records.
     * calls $delete_obj->beforeDelete($array_of_dependant_dataobjects, $this)
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
                
                if (count($chk->keys())) {
                    $matches = $chk->count();
                } else {
                    //DB_DataObject::DebugLevel(1);
                    $matches = $chk->count($ka[1]);
                }
                
                if ($matches) {
                    $match_ar[] = clone($chk);
                    continue;
                }          
            }
            
            $has_beforeDelete = method_exists($xx, 'beforeDelete');
            // before delte = allows us to trash dependancies if needed..
            $match_total = 0;
            
            if ( $has_beforeDelete ) {
                if ($xx->beforeDelete($match_ar, $this) === false) {
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
                    $chk->{$ka[1]} =  $xx->{$this->key};
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
            
            if (method_exists($xx,'onDelete')) {
                $xx->onDelete($req, $this);
            }
            
            
        }
        if ($errs) {
            $this->jerr(implode("\n<BR>", $errs));
        }
        $this->jok("Deleted");
        
    }
   
    
    /**
     * cols stores the list of columns that are available from the query.
     *
     *
     * This is a dupe of what is in autojoin -- we should move to using autojoin really.
     *
     *
     * // changes:
     
      countWhat
      cols
      $this->colsJoinName
    
     *
     */
    
    var $cols = array();
    
    
    
    function loadMap($do, $cfg =array()) //$onlycolumns=false, $distinct = false) 
    {
        //DB_DataObject::debugLevel(5);
        $onlycolumns    = !empty($cfg['columns']) ? $cfg['columns'] : false;
        $distinct       = !empty($cfg['distinct']) ? $cfg['distinct'] : false;
        $excludecolumns = !empty($cfg['exclude']) ? $cfg['exclude'] : array();
          
        $excludecolumns[] = 'passwd'; // we never expose passwords
       
        $ret = $do->autoJoin(array(
            'include' => $onlycolumns,
            'exclude' => $excludecolumns,
            'distinct' => $distinct
        ));
        
      
        
        $this->countWhat = $ret['count'];
        $this->cols = $ret['cols'];
        $this->colsJname = $ret['join_names'];
        
        
        return;
        
        /*
        
        
        
        //var_dump($cfg);exit;
        $this->countWhat = false;
        
        $conf = array();
        
        
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
        
        if ($excludecolumns) {
            // array diff - what's not in both..
            
            $selectAs = array(array(  array_intersect($xx,array_diff($xx , $excludecolumns)), '%s', false));
        } else {
        
            $selectAs = array(array(  $xx , '%s', false));
        }
        $has_distinct = false;
        if ($onlycolumns || $distinct) {
            $cols = array();
             //echo '<PRE>' ;print_r($xx);exit;
            foreach($xx as $c) {
                //var_dump($c);
                
                if ($distinct && $distinct == $c) {
                    $has_distinct = 'DISTINCT( ' . $do->tableName() .'.'. $c .') as ' . $c;
                    $this->countWhat =  'DISTINCT  ' . $do->tableName() .'.'. $c .'';
                    continue;
                }
                if (!$onlycolumns || in_array($c, $onlycolumns)) {
                    $cols[] = $c;
                }
            }
            
            
            $selectAs = empty($cols) ?  array() : array(array(  $cols , '%s', false)) ;
            
            
            
        } 
        //var_dump($selectAs);exit;
        $this->cols = array();
        $this->colsJname =array();
        foreach($xx as $k) {
            $this->cols[$k] = $do->tableName(). '.' . $k;
        }
        
        
        
        
        //var_dump($map);exit;
        
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
            
            //var_dump($xx);
            if ($onlycolumns || $distinct) {
                $cols = array();
                foreach($xx as $c) {
                    $tn = sprintf($ocl.'_%s', $c);
                      
                    if ($distinct && $tn == $distinct) {
                        
                        $has_distinct = 'DISTINCT( ' . 'join_'.$ocl.'_'.$col.'.'.$c .')  as ' . $tn ;
                        $this->countWhat =  'DISTINCT  join_'.$ocl.'_'.$col.'.'.$c;
                       // var_dump($this->countWhat );
                        continue;
                    }
                    
                    
                    if (!$onlycolumns || in_array($tn, $onlycolumns)) {
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
        //var_dump($selectAs);exit;
        if ($has_distinct) {
            $do->selectAdd($has_distinct);
        }
         
        // we do select as after everything else as we need to plop distinct at the beginning??
        /// well I assume..
        //echo '<PRE>';print_r($selectAs);exit;
        foreach($selectAs as $ar) {
            $do->selectAs($ar[0], $ar[1], $ar[2]);
        }
        */
        
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
        
        //echo '<PRE>';print_r($this->cols);exit;
        //echo '<PRE>';print_r($rdata);exit;
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
            if (false === $x->applyFilters($q, $this->authUser, $this)) {
                return; 
            } 
        }
        $q_filtered = array();
        
        $keys = $x->keys();
        // var_dump($keys);exit;
        foreach($q as $key=>$val) {
            
            if (in_array($key,$keys)) {
               
                $x->$key  = $val;
            }
            
             // handles name[]=fred&name[]=brian => name in ('fred', 'brian').
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
            
            
            // handles !name=fred => name not equal fred.
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
                    if (strlen($val) && $key[0] != '_') {
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
        if (!empty($q_filtered)) {
            //var_dump($q_filtered);
            
            
            
            $x->setFrom($q_filtered);
        }
        
        
        
       
        // nice generic -- let's get rid of it.. where is it used!!!!
        // used by: 
        // Person / Group / most of my queries noww...
        if (!empty($q['query']['name'])) {
            
            
            if (in_array( 'name',  array_keys($x->table()))) {
                $x->whereAdd($x->tableName().".name LIKE '". $x->escape($q['query']['name']) . "%'");
            }
        }
        
        // - projectdirectory staff list - persn queuy
     
        
    }
    /**
     * create the  dataobject from (usually the url)
     * This uses $this->validTables
     *           $this->validPrefix (later..)
     * to determine if class can be created..
     *
     */
     
    function dataObject($tab)
    {
        if (is_array($this->validTables) &&  !in_array($tab,$this->validTables)) {
            $this->jerr("Invalid url - not listed in validTables");
        }
        $tab = str_replace('/', '',$tab); // basic protection??
        
        $x = DB_DataObject::factory($tab);
        
        if (!is_a($x, 'DB_DataObject')) {
            $this->jerr('invalid url - no dataobject');
        }
        return $x;
        
    }
    
     
    // our handlers to commit / rollback.
    
      
    
    function jok($str)
    {
        // note that commit will only work if an insert/update was done,
        // so some stored proc calls may not have flagged this.
        
        if ($this->transObj ) {
            $this->transObj->query( connection_aborted() ? 'ROLLBACK' :  'COMMIT');
        }
        return parent::jok($str);
    }
    
    
    function jerr($str, $errors=array(), $content_type = false)
    {
        // standard error reporting..
        if ($this->transObj) {
            $this->transObj->query('ROLLBACK');
        }
        return parent::jerr($str,$errors,$content_type);
    
    }
    
    
    
    
    
}
