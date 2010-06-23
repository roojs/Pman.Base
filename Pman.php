<?php 
/**
 * Pman Base class
 * 
 * Provides:
 *  - base application setup (variables etc to javascript)
 * 
 *  - authentication and permission info about user / application
 *  - json output methods.
 *  - file upload error checking - checkFileUploadError
 *  - logging to event table
 *  - sendTemplate code (normally use the Person version for sending to specific people..)
 * 
 *  - doc managment code?? - remarks and tracking??? - MOVEME
 *  - authentication link checking?? MOVEME?
 *  - authentication reset password ?? MOVEME?
 *  ?? arrayClean.. what's it doing here?!? ;)
 * 
 * 
 */

class Pman extends HTML_FlexyFramework_Page 
{
    var $appName= "";
    var $appShortName= "";
    var $appVersion = "1.8";
    var $version = 'dev';
    var $onloadTrack = 0;
    var $linkFail = "";
    var $showNewPass = 0;
    var $logoPrefix = '';
    var $appModules = '';
    
    
   
    
    /**
     * ------------- Standard getAuth/get/post methods of framework.
     * 
     * 
     */
    
    function getAuth() // everyone allowed in!!!!!
    {
        $this->loadOwnerCompany();
        
        return true;
        
    }
    
    function init() 
    {
        if (isset($this->_hasInit)) {
            return;
            
        }
        $this->_hasInit = true;
        /*
        if (method_exists('HTML_FlexyFramework', 'get')) {
        */    
            $boot = HTML_FlexyFramework::get();
           // echo'<PRE>';print_R($boot);exit;
            $this->appName= $boot->appName;
            $this->appNameShort= $boot->appNameShort;
            $this->appModules= $boot->enable;
            $this->isDev = true; //empty($opts['isDev']) ? '' : $opts['isDev'];
            $this->appDisable = $boot->disable;
            $this->version = $boot->version;
        /*    
        } else {
            // BC!!!
           
            $opts = PEAR::getStaticProperty('Pman', 'options');  
            
            $this->isDev = true; //empty($opts['isDev']) ? '' : $opts['isDev'];
            
            $this->appName= empty($opts['appName']) ? '' : $opts['appName'];
            $this->appNameShort= empty($opts['appNameShort']) ? '' : $opts['appNameShort'];
            $this->appModules= $opts['enable'];
            $this->appDisable = $opts['disable'];
            $this->version = isset($opts['version']) ? $this->version : $opts['version'];
        }
        */
    }
    
    function get($base) 
    {
        $this->init();
            //$this->allowSignup= empty($opts['allowSignup']) ? 0 : 1;
        $bits = explode('/', $base);
        //print_R($bits);
        if ($bits[0] == 'Link') {
            $this->linkFail = $this->linkAuth(@$bits[1],@$bits[2]);
            header('Content-type: text/html; charset=utf-8');
            return;
        } 
        if ($bits[0] == 'PasswordReset') {
            $this->linkFail = $this->resetPassword(@$bits[1],@$bits[2],@$bits[3]);
            header('Content-type: text/html; charset=utf-8');
            return;
        } 
        
        
        if ($this->getAuthUser()) {
            $this->addEvent("RELOAD");
        }
        
        
        if (strlen($base)) {
            $this->addEvent("BADURL", false, $base);
            $this->jerr("invalid url");
        }
        // deliver template
        if (isset($_GET['onloadTrack'])) {
            $this->onloadTrack = (int)$_GET['onloadTrack'];
        }
        // getting this to work with xhtml is a nightmare
        // = nbsp / <img> issues screw everyting up.
        
        //header('Content-type: application/xhtml+xml; charset=utf-8');
        header('Content-type: text/html; charset=utf-8');
         
    }
    function post($base) {
        return $this->get($base);
    }
    
    /**
     * ------------- Authentication and permission info about logged in user!!!
     * 
     * 
     */
    
    function loadOwnerCompany()
    {
        $this->company = DB_DataObject::Factory('Companies');
        if ($this->company) { // non-core pman projects
            return; 
        }
        $this->company->get('comptype', 'OWNER');
        
    }
    function staticGetAuthUser()
    {
        $ff = HTML_FlexyFramework::get();
        $tbl = empty($ff->Pman['authTable']) ? 'Person' : $ff->Pman['authTable'];
        
        $u = DB_DataObject::factory($tbl);
        if (!$u->isAuth()) {
            return false;
        }
        return $u->getAuthUser();
    }
    function getAuthUser()
    {
        if (!empty($this->authUser)) {
            return $this->authUser;
        }
        $ff = HTML_FlexyFramework::get();
        $tbl = empty($ff->Pman['authTable']) ? 'Person' : $ff->Pman['authTable'];
        
        $u = DB_DataObject::factory( $tbl );
        if (!$u->isAuth()) {
            return false;
        }
        $this->authUser =$u->getAuthUser();
        return $this->authUser ;
    }
    function hasPerm($name, $lvl)  // do we have a permission
    {
        static $pcache = array();
        $au = $this->getAuthUser();
        return $au->hasPerm($name,$lvl);
        
    }
    function hasModule($name) 
    {
        $this->init();
        if (!strpos( $name,'.') ) {
            // use enable / disable..
            
            
            $enabled =  array('Core') ;
            $enabled = !empty($this->appModules) ? 
                array_merge($enabled, explode(',',  $this->appModules)) : 
                $enabled;
            $disabled =  explode(',', $this->appDisable ? $this->appDisable: '');
            
            //print_R($opts);
            
            return in_array($name, $enabled) && !in_array($name, $disabled);
        }
        
        $x = DB_DataObject::factory('Group_Rights');
        $ar = $x->defaultPermData();
        if (empty($ar[$name]) || empty($ar[$name][0])) {
            return false;
        }
        return true;
    }
    
    
    
    
    /**
     * ---------------- Global Tools ---------------   
     */
    
    
    
    /**
     * send a template to the user
     * rcpts are read from the resulting template.
     * 
     * @arg $templateFile  - the file in mail/XXXXXX.txt
     * @arg $args  - variables available to the form as {t.*} over and above 'this'
     * 
     * 
     */
    
    function sendTemplate($templateFile, $args)
    {
        
        
        
        $content  = clone($this);
        
        foreach((array)$args as $k=>$v) {
            $content->$k = $v;
        }
        $content->msgid = md5(time() . rand());
        
        $content->HTTP_HOST = $_SERVER["HTTP_HOST"];
        /* use the regex compiler, as it doesnt parse <tags */
        require_once 'HTML/Template/Flexy.php';
        $template = new HTML_Template_Flexy( array(
                 'compiler'    => 'Regex',
                 'filters' => array('SimpleTags','Mail'),
            //     'debug'=>1,
            ));
        
        // this should be done by having multiple template sources...!!!
         
        $template->compile('mail/'. $templateFile.'.txt');
        
        /* use variables from this object to ouput data. */
        $mailtext = $template->bufferedOutputObject($content);
        //echo "<PRE>";print_R($mailtext);
        
        /* With the output try and send an email, using a few tricks in Mail_MimeDecode. */
        require_once 'Mail/mimeDecode.php';
        require_once 'Mail.php';
        
        $decoder = new Mail_mimeDecode($mailtext);
        $parts = $decoder->getSendArray();
        if (PEAR::isError($parts)) {
            return $parts;
            //echo "PROBLEM: {$parts->message}";
            //exit;
        } 
        list($recipents,$headers,$body) = $parts;
        ///$recipents = array($this->email);
        $mailOptions = PEAR::getStaticProperty('Mail','options');
        $mail = Mail::factory("SMTP",$mailOptions);
        $headers['Date'] = date('r');
        if (PEAR::isError($mail)) {
            return $mail;
        } 
        $oe = error_reporting(E_ALL ^ E_NOTICE);
        $ret = $mail->send($recipents,$headers,$body);
        error_reporting($oe);
       
        return $ret;
    
    }
    
    function checkFileUploadError()  // check for file upload errors.
    {    
        if (
            empty($_FILES['File']) 
            || empty($_FILES['File']['name']) 
            || empty($_FILES['File']['tmp_name']) 
            || empty($_FILES['File']['type']) 
            || !empty($_FILES['File']['error']) 
            || empty($_FILES['File']['size']) 
        ) {
            $this->jerr("File upload error: <PRE>" . print_r($_FILES,true) . print_r($_POST,true) . "</PRE>");
        }
    }
    
    
    /**
     * generate a tempory file with an extension (dont forget to delete it)
     */
    
    function tempName($ext)
    {
        $x = tempnam(ini_get('session.save_path'), HTML_FlexyFramework::get()->appNameShort.'TMP');
        unlink($x);
        return $x .'.'. $ext;
    }
    /**
     * ------------- Authentication testing ------ ??? MOVEME?
     * 
     * 
     */
    function linkAuth($trid, $trkey) 
    {
        $tr = DB_DataObject::factory('Documents_Tracking');
        if (!$tr->get($trid)) {
            return "Invalid URL";
        }
        if (strtolower($tr->authkey) != strtolower($trkey)) {
            $this->AddEvent("ERROR-L", false, "Invalid Key");
            return "Invalid KEY";
        }
        // check date..
        $this->onloadTrack = (int) $tr->doc_id;
        if (strtotime($tr->date_sent) < strtotime("NOW - 14 DAYS")) {
            $this->AddEvent("ERROR-L", false, "Key Expired");
            return "Key Expired";
        }
        // user logged in and not
        $au = $this->getAuthUser();
        if ($au && $au->id && $au->id != $tr->person_id) {
            $au->logout();
            
            return "Logged Out existing Session\n - reload to log in with correct key";
        }
        if ($au) { // logged in anyway..
            $this->AddEvent("LOGIN", false, "With Key (ALREADY)");
            header('Location: ' . $this->baseURL.'?onloadTrack='.$this->onloadTrack);
            exit;
            return false;
        }
        
        // authenticate the user...
        // slightly risky...
        $u = DB_DataObject::factory('Person');
         
        $u->get($tr->person_id);
        $u->login();
        $this->AddEvent("LOGIN", false, "With Key");
        
        // we need to redirect out - otherwise refererer url will include key!
        header('Location: ' . $this->baseURL.'?onloadTrack='.$this->onloadTrack);
        exit;
        
        return false;
        
        
        
        
    }
    
    
    /**
     * ------------- Authentication password reset ------ ??? MOVEME?
     * 
     * 
     */
    
    
    function resetPassword($id,$t, $key)
    {
        
        $au = $this->getAuthUser();
        if ($au) {
            return "Already Logged in - no need to use Password Reset";
        }
        
        $u = DB_DataObject::factory('Person');
        //$u->company_id = $this->company->id;
        $u->active = 1;
        if (!$u->get($id) || !strlen($u->passwd)) {
            return "invalid id";
        }
        
        // validate key.. 
        if ($key != $u->genPassKey($t)) {
            return "invalid key";
        }
        $uu = clone($u);
        $u->no_reset_sent = 0;
        $u->update($uu);
        
        if ($t < strtotime("NOW - 1 DAY")) {
            return "expired";
        }
        $this->showNewPass = implode("/", array($id,$t,$key));
        return false;
    }
    
     
    /**
     * ---------------- Standard JSON outputers. - used everywhere
     */
    
    function jerr($str, $errors=array()) // standard error reporting..
    {
        require_once 'Services/JSON.php';
        $json = new Services_JSON();
        
        if (!empty($_REQUEST['returnHTML']) || 
            (isset($_SERVER['CONTENT_TYPE']) && preg_match('#multipart/form-data#i', $_SERVER['CONTENT_TYPE']))
        ) {
            header('Content-type: text/html');
            echo "<HTML><HEAD></HEAD><BODY>";
            echo  $json->encodeUnsafe(array(
                    'success'=> false, 
                    'errorMsg' => $str,
                     'message' => $str, // compate with exeption / loadexception.

                    'errors' => $errors ? $errors : true, // used by forms to flag errors.
                    'authFailure' => !empty($errors['authFailure']),
                ));
            echo "</BODY></HTML>";
            exit;
        }
       
        echo $json->encode(array(
            'success'=> false, 
            'data'=> array(), 
            'errorMsg' => $str,
            'message' => $str, // compate with exeption / loadexception.
            'errors' => $errors ? $errors : true, // used by forms to flag errors.
            'authFailure' => !empty($errors['authFailure']),
        ));
        exit;
        
    }
    function jok($str)
    {
        
        require_once 'Services/JSON.php';
        $json = new Services_JSON();
        
        if (!empty($_REQUEST['returnHTML']) || 
            (isset($_SERVER['CONTENT_TYPE']) && preg_match('#multipart/form-data#i', $_SERVER['CONTENT_TYPE']))
        
        ) {
            header('Content-type: text/html');
            echo "<HTML><HEAD></HEAD><BODY>";
            echo  $json->encodeUnsafe(array('success'=> true, 'data' => $str));
            echo "</BODY></HTML>";
            exit;
        }
         
        
        echo  $json->encode(array('success'=> true, 'data' => $str));
        exit;
        
    }
    function jdata($ar,$total=false, $extra=array())
    {
        // should do mobile checking???
        if ($total == false) {
            $total = count($ar);
        }
        $extra=  $extra ? $extra : array();
        require_once 'Services/JSON.php';
        $json = new Services_JSON();
        echo $json->encode(array('success' =>  true, 'total'=> $total, 'data' => $ar) + $extra);
        exit;
        
    }
    
    
   
   
      
    /**
     * ---------------- Page output?!?!?
     */
    
    
    function hasBg($fn) // used on front page to check if logos exist..
    {
        return file_exists($this->rootDir.'/Pman/'.$this->appNameShort.'/templates/images/'.  $fn);
    }
    
    function outputJavascriptIncludes() // includes on devel version..
    {
        
        $mods = explode(',', $this->appModules);
        array_unshift($mods,   'Core');
        $mods = array_unique($mods);
        
        foreach($mods as $mod) {
            // add the css file..
            
            $files = $this->moduleJavascriptList($mod.'/widgets');
            foreach($files as $f) {
                echo '<script type="text/javascript" src="'. $f. '"></script>'."\n";
            }
            
            $files = $this->moduleJavascriptList($mod);
            foreach($files as $f) {
                echo '<script type="text/javascript" src="'. $f. '"></script>'."\n";
            }
            
        }
         
    }
    
    function outputCSSIncludes() // includes on CSS links.
    {
        
        $mods = explode(',', $this->appModules);
        array_unshift($mods,   'Core');
        $mods = array_unique($mods);
        
        foreach($mods as $mod) {
            // add the css file..
            $css = $this->rootDir.'/Pman/'.$mod.'/'.strtolower($mod).'.css';
            if (file_exists( $css)){
                $css = $this->rootURL .'/Pman/'.$mod.'/'.strtolower($mod).'.css';
                echo '<link rel="stylesheet" type="text/css" href="'.$css.'" />'."\n";
            }
             
            
        }
         
    }
    

    
    
    function moduleJavascriptList($mod)
    {
        $dir =   $this->rootDir.'/Pman/'. $mod;
            
        $path =    $this->rootURL."/Pman/$mod/";
        $base = dirname($_SERVER['SCRIPT_FILENAME']);
        $cfile = realpath($base .'/_compiled_/' . $mod . '.js');
        $lfile = realpath($base .'/_translations_/' . $mod . '.js');
        //    var_dump($cfile);
        if (!file_exists($dir)) {
            return array();
        }
        $dh = opendir($dir);
        $maxtime = 0;
        $ctime = 0;
        $files = array();
        if (file_exists($cfile)) {
           // $ctime = max(filemtime($cfile), filectime($cfile));
            // otherwise use compile dfile..
            $files = array( $this->rootURL."/_compiled_/". basename($cfile));
            if (file_exists($lfile)) {
                array_push($files, $this->rootURL."/_translations_/$mod.js");
            }
            return $files;
        }
        // works out if stuff has been updated..
        // technically the non-dev version should output compiled only?!!?
        while (false !== ($f = readdir($dh))) {
            if (!preg_match('/\.js$/', $f)) {
                continue;
            }
            // got the 'module file..'
        
            $maxtime = max(filemtime($dir . '/'. $f), $maxtime);
            $files[] = $path . $f;
        }
        if (empty($files)) {
            return;
        }
       // var_dump(array($maxtime , $ctime)); 
        //if ($maxtime > $ctime) {
            $lsort = create_function('$a,$b','return strlen($a) > strlen($b) ? 1 : -1;');
            usort($files, $lsort);
           // if (file_exists($lfile)) {
           //     array_unshift($files, $this->rootURL."/_translations_/$mod.js");
            //}
            return $files;
       // }
        
    }
    
    
    
    /**
     * ---------------- Logging ---------------   
     */
    
    
    
    
    
    function addEvent($act, $obj = false, $remarks = '') {
        $au = $this->getAuthUser();
        $e = DB_DataObject::factory('Events');
        $e->person_name = $au ? $au->name : '';
        $e->person_id = $au ? $au->id : '';
        $e->event_when = date('Y-m-d H:i:s');
        $e->ipaddr = $_SERVER["REMOTE_ADDR"];
        $e->action = $act;
        $e->on_table = $obj ? $obj->tableName() : '';
        $e->on_id  = $obj ? $obj->id : 0;
        $e->remarks = $remarks;
        $e->insert();
        
    }

    
     
    
}
