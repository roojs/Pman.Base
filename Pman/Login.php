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
        
        
        
        
        if (!empty($_REQUEST['logout'])) {
            $u = $this->getAuthUser();
            //print_r($u);
            if ($u) {
                $this->addEvent('LOGOUT');
                $u->logout();
            }
            // log it..
            
            $_SESSION['Pman_I18N'] = array();
            
        }
        
        // general query...
        if (!empty($_REQUEST['getAuthUser'])) {
            
            $this->sendAuthUserDetails();
            exit;
           
        }
        if (!empty($_REQUEST['username'])) {
            $this->post();
        }
        $this->jerr("INVALID REQUEST");
        exit;
    }
    
    function sendAuthUserDetails()
    {
       // DB_DataObject::debugLevel(1);
        $ff = HTML_FlexyFramework::get();
        $tbl = empty($ff->Pman['authTable']) ? 'Person' : $ff->Pman['authTable'];
        
        $u = DB_DataObject::factory($tbl);
        if (!$u->isAuth()) {
            $this->jok(array('id' => 0)); // not logged in..
            exit;
        }
        $au = $u->getAuthUser();
        
        $aur = $au->authUserArray();
         
        /** -- these need modulizing somehow! **/
        
        if ($this->hasModule('Fax')) {
            // should check fax module???
            $f = DB_DataObject::factory('Fax_Queue');
            $aur['faxMax'] = $f->getMaxId();
            $aur['faxNumPending'] = $f->getNumPending();
        }
        
        if ($this->hasModule('Documents')) {
        // inbox...
            $d = DB_DataObject::factory('Documents_Tracking');
            $d->person_id = $au->id;
            //$d->status = 0; // unread
            $d->whereAdd('date_read IS NULL');
            $d->applyFilters(array('query'=> array('unread' => 1)), $au);
            $aur['inbox_unread'] = $d->count();
        }
        
        //echo '<PRE>';print_r($aur);
        
        $this->jok($aur);
        exit;
        
            
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
        $tbl = empty($ff->Pman['authTable']) ? 'Person' : $ff->Pman['authTable'];
        
       
        $u = DB_DataObject::factory($tbl);
        //$u->active = 1;
        
        
        if (empty($_REQUEST['username'])) { //|| (strpos($_REQUEST['username'], '@') < 1)) {
            $this->jerr('You typed the wrong Username or Password (0)');
            exit;
        }
        
        $u->authUserName($_REQUEST['username']);
        
        
        if ($u->count() > 1 || !$u->find(true)) {
            $this->jerr('You typed the wrong Username or Password  (1)');
            exit;
        }
        
        if (!$u->active()) {
            $this->jerr('Account disabled');
        }
        
        if ($u->checkPassword($_REQUEST['password'])) {
            $u->login();
            $this->addEvent("LOGIN");
            if (!empty($_REQUEST['lang'])) {
                $u->lang($_REQUEST['lang']);
            }
             // log it..
            
            $this->sendAuthUserDetails();
            exit;

            //exit;
        }
        
         
        $this->jerr('You typed the wrong Username or Password  (2)'); // - " . htmlspecialchars(print_r($_POST,true))."'");
        exit;
    }
    
    function passwordRequest($n) 
    {
        $u = DB_DataObject::factory('Person');
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
        $this->authFrom = time();
        $this->authKey = $u->genPassKey($this->authFrom);
        $this->authKey = md5($u->email . $this->authFrom . $u->passwd);
        
        $ret =  $u->sendTemplate('password_reset', $this);
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
        
        $u = DB_DataObject::factory('Person');
        
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
        
        $this->jok($u);
    }
    
    
    
}

