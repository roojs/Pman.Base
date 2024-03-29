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
    
    var $ip_management = false;
	
	var $event_suffix = '';
	
	// for forgot password email	
	var $authFrom;
	var $authKey;
	var $person;
	var $bcc;
	var $rcpts;

    
    function getAuth() // everyone allowed in here..
    {
        parent::getAuth(); // load company..
        
        $ff = HTML_FlexyFramework::get();
        
        $this->ip_management = (empty($ff->Pman['ip_management'])) ? false : true;
        
        return true;
    }
    /**
     * Accepts:
     * logout =
     * 
     * 
     */
    function get($v, $opts=array()) 
    {
        $this->initErrorHandling();
        
         // DB_DataObject::DebugLevel(5);
        if (!empty($_REQUEST['logout'])) {
           return $this->logout();
        }
        
        // general query...
        if (!empty($_REQUEST['getAuthUser'])) {
            //DB_Dataobject::debugLevel(5);
            $this->sendAuthUserDetails();
            exit;
        }
        
        if(!empty($_REQUEST['check_owner_company'])) {
            $core_company = DB_DataObject::factory('core_company');
            $core_company->comptype = 'OWNER';
            $this->jok($core_company->count());
        }
        
        // might be an idea to disable this?!?
        if (!empty($_REQUEST['username'])) {
            $this->post();
        }
        
        
        if (!empty($_REQUEST['switch'])) {
            $this->switchUser($_REQUEST['switch']);
        }
        
        if (!empty($_REQUEST['loginPublic'])) {
            $this->switchPublicUser($_REQUEST['loginPublic']);
        }
        if (!empty($_SERVER['HTTP_USER_AGENT']) && preg_match('/^check_http/', $_SERVER['HTTP_USER_AGENT'])) {
			die("server is alive = authFailure"); // should really use heartbeat now..
		}
        $this->jerror("NOTICE-INVALID", "INVALID REQUEST");
        exit;
    }
    
    
    function logout()
    {
        $ff = class_exists('HTML_FlexyFramework2') ?  HTML_FlexyFramework2::get()  :  HTML_FlexyFramework::get();
        
		//DB_DAtaObject::debugLevel(1);
        $u = $this->getAuthUser();
        //print_r($u);
        if ($u) {
            
            $this->addEvent('LOGOUT'. $this->event_suffix);
            $e = DB_DataObject::factory('Events');
          
            
            $u->logout();
            session_regenerate_id(true);
            session_commit(); 

            if(!empty($ff->Pman['local_autoauth']) && !empty($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] == 'localhost') {
                $this->jerr("you are using local autoauth!?");                
            }
            //echo '<PRE>';print_R($_SESSION);
            $this->jok("Logged out - user ");
        }
        // log it..
        
        //$_SESSION['Pman_I18N'] = array(); << 
        session_regenerate_id(true);
        session_commit();
        
        $this->jok("Logged out - no user");
        
    }
    
    function sendAuthUserDetails()
    {
        // remove for normal use - it's a secuirty hole!
        //DB_DataObject::debugLevel(1);
        if (!empty($_REQUEST['_debug'])) {
           // DB_DataObject::debugLevel(1);
        }
        // 
        $ff = HTML_FlexyFramework::get();
        $tbl = empty($ff->Pman['authTable']) ? 'core_person' : $ff->Pman['authTable'];
        
        $u = DB_DataObject::factory($tbl);
        $s = DB_DataObject::factory('core_setting');
        $require_oath_val = 1;
        $require_oath = $s->lookup('core', 'two_factor_auth_required');
        if(!empty($require_oath)) {
            if($require_oath->val == 0) {
                $require_oath_val = 0;
            }
        } 
        
        if (!$u->isAuth()) {
            $this->jok(array(
                'id' => 0
            ));
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
        $this->addEvent("LOGIN-SWITCH-USER". $this->event_suffix, false, $au->name . ' TO ' . $u->name);
        $this->jok("SWITCH");
        
    }
    
    function switchPublicUser($id)
    {
        $tbl = empty($ff->Pman['authTable']) ? 'core_person' : $ff->Pman['authTable'];
        
        $u = DB_DataObject::factory($tbl);
        $u->get($id);
        
        if (!$u->active()) {
            $this->jerr('Account disabled');
        }
        
        if(!$u->loginPublic()){
            $this->jerr('Switch fail');
        }
         
        $this->jok('OK');
    }
    
    var $domObj = false;
    
    function post($v)
    {
        //DB_DataObject::debugLevel(1);
        
        if (!empty($_REQUEST['getAuthUser'])) {
            $this->sendAuthUserDetails();
            exit;
        }
        
        if (!empty($_REQUEST['logout'])) {
           return $this->logout();
        }
         
        if(!empty($_REQUEST['check_owner_company'])) {
            $core_company = DB_DataObject::factory('core_company');
            $core_company->comptype = 'OWNER';
            $this->jok($core_company->count());
        }
        
        if (!empty($_REQUEST['passwordRequest'])) { //|| (strpos($_REQUEST['username'], '@') < 1)) {
            return $this->passwordRequest($_REQUEST['passwordRequest']);   
        }
			
		if (!empty($_REQUEST['ResetPassword'])) {
			if (empty($_REQUEST['id']) || 
			empty($_REQUEST['ts']) ||
			empty($_REQUEST['key']) ||
			empty($_REQUEST['password1']) ||
			empty($_REQUEST['password2']) ||
			($_REQUEST['password1'] != $_REQUEST['password2'])
			) {
			$this->jerr("Invalid request to reset password");
			}
			
			$this->resetPassword($_REQUEST['id'], $_REQUEST['ts'], $_REQUEST['key'], $_REQUEST['password1'] );
		}
		
		
		if (!empty($_REQUEST['_verifyCheckSum'])) {
			if (empty($_REQUEST['id']) || 
			empty($_REQUEST['ts']) ||
			empty($_REQUEST['key'])
			 
			) {
			$this->jerr("Invalid request to reset password");
			}
			
			$this->verifyResetPassword($_REQUEST['id'], $_REQUEST['ts'], $_REQUEST['key']);
			$this->jok("Checksum is ok");
		}
	
	// this is 'classic' change password...
        if (!empty($_REQUEST['changePassword'])) {
            return $this->changePassword($_REQUEST);
        }
        
        // login attempt..
        
        $ff = HTML_FlexyFramework::get();
        $tbl = empty($ff->Pman['authTable']) ? 'core_person' : $ff->Pman['authTable'];
        
       
        $u = DB_DataObject::factory($tbl);
        
        $ip = $this->ip_lookup();
        // ratelimit
        if (!empty($ip)) {
            //DB_DataObject::DebugLevel(1);
            $e = DB_DataObject::Factory('Events');
            $e->action = 'LOGIN-BAD'. $this->event_suffix;
            $e->ipaddr = $ip;
            $e->whereAdd('event_when > NOW() - INTERVAL 10 MINUTE');
            if ($e->count() > 5) {
                $this->jerror('LOGIN-RATE'. $this->event_suffix, "Login failures are rate limited - please try later");
            }
        }
        
	// this was removed before - not quite sure why.
	// when a duplicate login account is created, this stops the old one from interfering..
        $u->active = 1;
        
        // empty username = not really a hacking attempt.
        
        if (empty($_REQUEST['username'])) { //|| (strpos($_REQUEST['username'], '@') < 1)) {
            $this->jerror('LOGIN-EMPTY'. $this->event_suffix, 'You typed the wrong Username or Password (0)');
            exit;
        }
        
        $u->authUserName($_REQUEST['username']);
        
        if ($u->count() > 1 || !$u->find(true)) {
            $this->jerror('LOGIN-BAD'. $this->event_suffix,'You typed the wrong Username or Password  (1)');
            exit;
        }
        
        if (!$u->active()) { 
            $this->jerror('LOGIN-BAD'. $this->event_suffix,'Account disabled');
        }
        
        if(!empty($u->oath_key) && empty($_REQUEST['oath_password'])){
            $this->jerror('LOGIN-2FA'. $this->event_suffix,'Your account requires Two-Factor Authentication');
        }
        
        // check if config allows non-owner passwords.
        // auth_company = "OWNER" // auth_company = "CLIENT" or blank for all?
        // perhaps it should support arrays..
        $ff= HTML_FlexyFramework::get();
        if (!empty($ff->Pman['auth_comptype']) && $ff->Pman['auth_comptype'] != $u->company()->comptype) {
            //print_r($u->company());
            $this->jerror('LOGIN-BADUSER'. $this->event_suffix, "Login not permited to outside companies"); // serious failure
        }
        
        
        // note we trim \x10 -- line break - as it was injected the front end
        // may have an old bug on safari/chrome that added that character in certian wierd scenarios..
        if (!$u->checkPassword(trim($_REQUEST['password'],"\x10"))) {
            $this->jerror('LOGIN-BAD'. $this->event_suffix, 'You typed the wrong Username or Password  (2)'); // - " . htmlspecialchars(print_r($_POST,true))."'");
            exit;
        }
        
        if(
            !empty($u->oath_key) &&
	    (
		empty($_REQUEST['oath_password']) ||
		!$u->checkTwoFactorAuthentication($_REQUEST['oath_password'])
	    )
        ) {
            $this->jerror('LOGIN-BAD'. $this->event_suffix, 'You typed the wrong Username or Password  (3)');
            exit;
        }
        
        $this->ip_checking();
        
        $u->login();
        // we might need this later..
        $this->addEvent("LOGIN". $this->event_suffix, false, session_id());
		
		
		
        if (!empty($_REQUEST['lang'])) {
			
			if (!empty($ff->languages['avail']) && !in_array($_REQUEST['lang'],$ff->languages['avail'])) {
				// ignore.	
			} else {
			
				$u->lang($_REQUEST['lang']);
			}
        }
         // log it..

        $this->sendAuthUserDetails();
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
            $this->jerr("no template  Admin password reset (ADMIN_PASSWORD_RESET) exists - please run importer ");
        }
		if (!$cm->active) {
			$this->jerr("template for Admin password reset has been disabled");
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
        if (!$cm->bcc_group_id || !$g->get($cm->bcc_group_id)) {
            $this->jerr("BCC for ADMIN_PASSWORD_RESET email has not been set");
        }
        $bcc = $g->members('email');
        if (!count($bcc)) {
            $this->jerr( "'BCC group for ADMIN_PASSWORD_RESET  does not have any members");
        }
        
        
        
        $this->authFrom = time();
        $this->authKey = $u->genPassKey($this->authFrom);
        //$this->authKey = md5($u->email . $this->authFrom . $u->passwd);
        $this->person = $u;
        $this->bcc = $bcc;
        $this->rcpts = $u->getEmailFrom();
        
	
		$mailer = $cm->toMailer($this, false);
		if (is_a($mailer,'PEAR_Error') ) {
			$this->addEvent('SYSERR',false, $mailer->getMessage());
			$this->jerr($mailer->getMessage());
		}
        $sent = $mailer->send();
		if (is_a($sent,'PEAR_Error') ) {
			$this->addEvent('SYSERR',false, $sent->getMessage());
			$this->jerr($sent->getMessage());
        }
	
        $this->addEvent('LOGIN-PASSREQ'. $this->event_suffix,$u, $u->email);
        $uu = clone($u);
        $uu->no_reset_sent++;
        $uu->update($u);
        $this->jok("done");
        
    }
    
    function verifyResetPassword($id,$t, $key)
    {
		$au = $this->getAuthUser();
		//print_R($au);
        if ($au) {
            $this->jerr( "Already Logged in - no need to use Password Reset");
        }
        
        $u = DB_DataObject::factory('core_person');
        //$u->company_id = $this->company->id;
        $u->active = 1;
        if (!$u->get($id) || !strlen($u->passwd)) {
            $this->jerr("Password reset link is not valid (id)");
        }
        
        // validate key.. 
        if ($key != $u->genPassKey($t)) {
            $this->jerr("Password reset link is not valid (key)");
        }
	
		if ($t < strtotime("NOW - 1 DAY")) {
            $this->jerr("Password reset link has expired");
        }
	return $u;
	
	
	
    }
    
    
    function resetPassword($id,$t, $key, $newpass )
    {
        
        $u = $this->verifyResetPassword($id,$t,$key);
	
	
        $uu = clone($u);
        $u->no_reset_sent = 0;
		if ($newpass != false) {
			$u->setPassword($newpass);
		}
        $u->update($uu);
		$this->addEvent("LOGIN-CHANGEPASS". $this->event_suffix, $u);

        $this->jok("Password has been Updated");
    }
    
    
    function changePassword($r)
    {   
        $au = $this->getAuthUser();
        if (!$au) {
			$this->jerr("Password change attempted when not logged in");
		}
		$uu = clone($au);
		$au->setPassword($r['passwd1']);
		$au->update($uu);
		$this->addEvent("LOGIN-CHANGEPASS". $this->event_suffix, $au);
		$this->jok($au);
			 
    }
    
    function ip_checking()
    {
        if(empty($this->ip_management)){
            return;
        }
        
        $ip = $this->ip_lookup();
        
        if(empty($ip)){
            $this->jerr('BAD-IP-ADDRESS', array('ip' => $ip));
        }
        
        $core_ip_access = DB_DataObject::factory('core_ip_access');
        
        if(!DB_DataObject::factory('core_ip_access')->count()){ // first ip we always mark it as approved..
            
            $core_ip_access = DB_DataObject::factory('core_ip_access');
            
            $core_ip_access->setFrom(array(
                'ip' => $ip,
                'created_dt' => $core_ip_access->sqlValue("NOW()"),
                'authorized_key' => md5(openssl_random_pseudo_bytes(16)),
                'status' => 1,
                'email' => (empty($_REQUEST['username'])) ? '' : $_REQUEST['username'],
                'user_agent' => (empty($_SERVER['HTTP_USER_AGENT'])) ? '' : $_SERVER['HTTP_USER_AGENT']
            ));
            
            $core_ip_access->insert();
            
            return;
        }
        
        $core_ip_access = DB_DataObject::factory('core_ip_access');
        
        if(!$core_ip_access->get('ip', $ip)){ // new ip
            
            $core_ip_access->setFrom(array(
                'ip' => $ip,
                'created_dt' => $core_ip_access->sqlValue("NOW()"),
                'authorized_key' => md5(openssl_random_pseudo_bytes(16)),
                'status' => 0,
                'email' => (empty($_REQUEST['username'])) ? '' : $_REQUEST['username'],
                'user_agent' => (empty($_SERVER['HTTP_USER_AGENT'])) ? '' : $_SERVER['HTTP_USER_AGENT']
            ));
            
            $core_ip_access->insert();
            
            $core_ip_access->sendXMPP();
            
            $this->jerror('NEW-IP-ADDRESS', "New IP Address = needs approving", array('ip' => $ip));
            
            return;
        }
        
        if(empty($core_ip_access->status)){
            $this->jerror('PENDING-IP-ADDRESS', "IP is still pending approval", array('ip' => $ip));
        }
        
        if($core_ip_access->status == -1){
            $this->jerror('BLOCKED-IP-ADDRESS', "Your IP is blocked", array('ip' => $ip));
            return;
        }
        
        if($core_ip_access->status == -2 && strtotime($core_ip_access->expire_dt) < strtotime('NOW')){
            $this->jerror('BLOCKED-IP-ADDRESS', "Your IP is blocked", array('ip' => $ip));
            return;
        }
        
        return;
    }
    
    function ip_lookup()
    {

        if (!empty($_SERVER['HTTP_CLIENT_IP'])){
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        
        return $_SERVER['REMOTE_ADDR'];
    }
}

