<?php

require_once 'Pman.php';

/***
* 
* Auth wrapper..
* 
* User class must provide the following features.
* 
* logout()
* isAuth() 
* getAuthUser();
* authUserArray() 
* active()  -- is user active. // or set prior to checking..
* authUserName(n) - sets the value prior to a find(true)
* checkPassword($_REQUEST['password'])) {
* login();
* lang(val) - to set the language..
*/



class Pman_Login extends Pman
{ 
    
    var $masterTemplate = 'login.html';
    
    function getAuth() // everyone allowed in here..
    {
        parent::getAuth(); // load company..
        return true;
        
    }
    /**
     * Accepts:
     * logout =
     * 
     * 
     */
    function get() 
    {
        
         
         //DB_DataObject::DebugLevel(1);
        if (!empty($_REQUEST['logout'])) {
            $u = $this->getAuthUser();
            //print_r($u);
            if ($u) {
                
                $this->addEvent('LOGOUT');
                $e = DB_DataObject::factory('Events');
              
                session_regenerate_id(true);
                $u->logout();
            }
            // log it..
            
            $_SESSION['Pman_I18N'] = array();
            session_regenerate_id(true);
            
            $this->jok("Logged out");
        }
        
        // general query...
        if (!empty($_REQUEST['getAuthUser'])) {
            //DB_Dataobject::debugLevel(5);
            $this->sendAuthUserDetails();
            exit;
           
        }
        
        // might be an idea to disable this?!?
        if (!empty($_REQUEST['username'])) {
            $this->post();
        }
        
        
        if (!empty($_REQUEST['switch'])) {
            $this->switchUser($_REQUEST['switch']);
        }
        
        
        $this->jerr("INVALID REQUEST");
        exit;
    }
    
    function sendAuthUserDetails()
    {
        // remove for normal use - it's a secuirty hole!
       ///DB_DataObject::debugLevel(1);
        if (!empty($_REQUEST['_debug'])) {
           // DB_DataObject::debugLevel(1);
        }
        // 
        $ff = HTML_FlexyFramework::get();
        $tbl = empty($ff->Pman['authTable']) ? 'core_person' : $ff->Pman['authTable'];
        
        $u = DB_DataObject::factory($tbl);
        if (!$u->isAuth()) {
             
            $this->jok(array('id' => 0)); // not logged in..
            exit;
        }
        //die("got here?");
        $au = $u->getAuthUser();
        
         // might occur on shared systems.
        $ff= HTML_FlexyFramework::get();
        
        if (!empty($ff->Pman['auth_comptype'])  && $au->id > 0 &&
                ($ff->Pman['auth_comptype'] != $au->company()->comptype)) {
            $au->logout();
            $this->jerr("Login not permited to outside companies - please reload");
        }
        
        //$au = $u->getAuthUser();
        
        $aur = $au ?  $au->authUserArray() : array();
        
        /** -- these need modulizing somehow! **/
        
        
        
        // basically calls Pman_MODULE_Login::sendAuthUserDetails($aur) on all the modules
        //echo '<PRE>'; print_r($this->modules());
        // technically each module should only add properties to an array named after that module..
        
        foreach($this->modules() as $m) {
            if (empty($m)) {
                continue;
            }
            if (!file_exists($this->rootDir.'/Pman/'.$m.'/Login.php')) {
                continue;
            }
            $cls = 'Pman_'.$m.'_Login';
            require_once 'Pman/'.$m.'/Login.php';
            $x = new $cls;
            $x->authUser = $au;
            $aur = $x->sendAuthUserDetails($aur);
        }
        
                 
//        
//        echo '<PRE>';print_r($aur);
//        exit;
        $this->jok($aur);
        exit;
        
            
    }

    
    function switchUser($id)
    {
        $tbl = empty($ff->Pman['authTable']) ? 'core_person' : $ff->Pman['authTable'];
        $u = DB_DataObject::factory($tbl);
        if (!$u->isAuth()) {
            $this->err("not logged in");
        }
        
        $au = $u->getAuthUser();
        
        
        // first check they have perms to do this..
        if (!$au|| ($au->company()->comptype != 'OWNER') || !$this->hasPerm('Core.Person', 'E')) {
            $this->jerr("User switching not permitted");
        }
        
        
        $u = DB_DataObject::factory($tbl);
        $u->get($id);
        if (!$u->active()) {
            $this->jerr('Account disabled');
        }
        $u->login();
            // we might need this later..
        $this->addEvent("SWITCH USER", false, $au->name . ' TO ' . $u->name);
        $this->jok("SWITCH");
        
    }
    
    
    var $domObj = false;
    function post()
    {
        //DB_DataObject::debugLevel(1);
        if (!empty($_REQUEST['getAuthUser'])) {
            $this->sendAuthUserDetails();
            exit;
        }
        
        
        if (!empty($_REQUEST['passwordRequest'])) { //|| (strpos($_REQUEST['username'], '@') < 1)) {
            
            return $this->passwordRequest($_REQUEST['passwordRequest']);
            
        }
        
        if (!empty($_REQUEST['changePassword'])) {
            return $this->changePassword($_REQUEST);
        }
        
        // login attempt..
        
        $ff = HTML_FlexyFramework::get();
        $tbl = empty($ff->Pman['authTable']) ? 'core_person' : $ff->Pman['authTable'];
        
       
        $u = DB_DataObject::factory($tbl);
        
        
        
        // ratelimit
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            //DB_DataObject::DebugLevel(1);
            $e = DB_DataObject::Factory('Events');
            $e->action = 'LOGIN-BAD';
            $e->ipaddr = $_SERVER['REMOTE_ADDR'];
            $e->whereAdd('event_when > NOW() - INTERVAL 10 MINUTE');
            if ($e->count() > 5) {
                $this->jerror('LOGIN-RATE', "Login failures are rate limited - please try later");
            }
        }
        
        //$u->active = 1;
        
        // empty username = not really a hacking attempt.
        
        if (empty($_REQUEST['username'])) { //|| (strpos($_REQUEST['username'], '@') < 1)) {
            $this->jerror('LOGIN-EMPTY', 'You typed the wrong Username or Password (0)');
            exit;
        }
        
        $u->authUserName($_REQUEST['username']);
        
        
        if ($u->count() > 1 || !$u->find(true)) {
            $this->jerror('LOGIN-BAD','You typed the wrong Username or Password  (1)');
            exit;
        }
        
        if (!$u->active()) {
            $this->jerror('LOGIN-BAD','Account disabled');
        }
        
        // check if config allows non-owner passwords.
        // auth_company = "OWNER" // auth_company = "CLIENT" or blank for all?
        // perhaps it should support arrays..
        $ff= HTML_FlexyFramework::get();
        if (!empty($ff->Pman['auth_comptype']) && $ff->Pman['auth_comptype'] != $u->company()->comptype) {
            $this->jerror('LOGIN-BADUSER', "Login not permited to outside companies"); // serious failure
        }
        
        
        // note we trim \x10 -- line break - as it was injected the front end
        // may have an old bug on safari/chrome that added that character in certian wierd scenarios..
        if ($u->checkPassword(trim($_REQUEST['password'],"\x10"))) {
            $u->login();
            // we might need this later..
            $this->addEvent("LOGIN", false, session_id());
            if (!empty($_REQUEST['lang'])) {
                $u->lang($_REQUEST['lang']);
            }
             // log it..
            
            $this->sendAuthUserDetails();
            exit;

            //exit;
        }
        
         
        $this->jerror('LOGIN-BAD', 'You typed the wrong Username or Password  (2)'); // - " . htmlspecialchars(print_r($_POST,true))."'");
        exit;
    }
    
    function passwordRequest($n) 
    {
        $u = DB_DataObject::factory('core_person');
        //$u->company_id = $this->company->id;
        
        $u->whereAdd('LENGTH(passwd) > 1');
        $u->email = $n;
        $u->active = 1;
        if ($u->count() > 1 || !$u->find(true)) {
            $this->jerr('invalid User (1)');
        }
        // got a avlid user..
        if (!strlen($u->passwd)) {
            $this->jerr('invalid User (2)');
        }
        // check to see if we have sent a request before..
        
        if ($u->no_reset_sent > 3) {
            $this->jerr('We have issued to many resets - please contact the Administrator');
        }
        
        
        
        
        // sort out sender.
        $cm = DB_DataObject::factory('core_email');
        if (!$cm->get('name', 'ADMIN_PASSWORD_RESET')) {
            $this->jerr("no template ADMIN_PASSWORD_RESET exists - please run importer ");
            
        }
        /*
        
        $g = DB_DAtaObject::factory('Groups');
        if (!$g->get('name', 'system-email-from')) {
            $this->jerr("no group 'system-email-from' exists in the system");
        }
        $from_ar = $g->members();
        if (count($from_ar) != 1) {
            $this->jerr(count($from_ar) ? "To many members in the 'system-email-from' group " :
                       "'system-email-from' group  does not have any members");
        }
        */
        
        
        
        // bcc..
        $g = DB_DAtaObject::factory('core_group');
        if (!$g->get('name', 'bcc-email')) {
            $this->jerr("no group 'bcc-email' exists in the system");
        }
        $bcc = $g->members('email');
        if (!count($bcc)) {
            $this->jerr( "'bcc-email' group  does not have any members");
        }
        
        
        
        $this->authFrom = time();
        $this->authKey = $u->genPassKey($this->authFrom);
        //$this->authKey = md5($u->email . $this->authFrom . $u->passwd);
        $this->person = $u;
        $this->bcc = $bcc;
        $this->rcpts = $u->getEmailFrom();
        
        $ret = $cm->send($this);
        //$this->jerr(print_r($r->toData(),true));
        
        if (is_object($ret)) {
            $this->addEvent('SYSERR',false, $ret->getMessage());
            $this->jerr($ret->getMessage());
        }
        $this->addEvent('PASSREQ',$u, $u->email);
        $uu = clone($u);
        $uu->no_reset_sent++;
        $uu->update($u);
        $this->jok("done");
        
        
    }
    function changePassword($r)
    {
        $au = $this->getAuthUser();
        if ($au) {
            $uu = clone($au);
            $au->setPassword($r['passwd1']);
            $au->update($uu);
            $this->addEvent("CHANGEPASS", $u);
            $this->jok($au);
        }
        // not logged in -> need to validate 
        if (empty($r['passwordReset'])) {
            $this->jerr("invalid request");
        }
        // same code as reset pasword
       
        $bits = explode('/', $r['passwordReset']);
        //print_R($bits);
      
        $res= $this->resetPassword(@$bits[0],@$bits[1],@$bits[2]);
          
        if ($res !== false) {
            $this->jerr($res);
        }
        // key is correct.. let's change password...
        
        $u = DB_DataObject::factory('core_person');
        
        //$u->company_id = $this->company->id;
        $u->whereAdd('LENGTH(passwd) > 1');
        $u->active = 1;
        if (!$u->get($bits[0])) {
           $this->jerr("invalid id"); // should not happen!!!!
        }
        $uu = clone($u);
        $u->setPassword($r['passwd1']);
        $u->update($uu);
        $u->login();
        $this->addEvent("CHANGEPASS", $u);
        $this->jok($u);
    }
    
    
    
}

