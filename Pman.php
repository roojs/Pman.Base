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
 * Usefull implemetors
 * DB_DataObject*:*toEventString (for logging - this is generically prefixed to all database operations.)
 *   - any data object where this method exists, the result will get prefixed to the log remarks
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
    var $appDisabled = array(); // array of disabled modules..
                    // (based on config option disable)
    
    var $authUser; // always contains the authenticated user..
    
   
    
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
          
        $boot = HTML_FlexyFramework::get();
        // echo'<PRE>';print_R($boot);exit;
        $this->appName= $boot->appName;
        $this->appNameShort= $boot->appNameShort;
        $this->appModules= $boot->enable;
        $this->isDev = empty($boot->Pman['isDev']) ? false : $boot->Pman['isDev'];
        $this->appDisable = $boot->disable;
        $this->appDisabled = explode(',', $boot->disable);
        $this->version = $boot->version;
        
        if (!empty($ff->Pman['local_autoauth']) && 
            ($_SERVER['SERVER_ADDR'] == '127.0.0.1') &&
            ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') 
        ) {
            $this->isDev = true;
        }
        
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
         //var_dump($this->isDev);
        // force regeneration on load for development enviroments..
        
        HTML_FlexyFramework::get()->generateDataobjectsCache($this->isDev);
        
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
    
    function modules()
    {
        // appModules/appDisable contain a comma limited list of
        // both modules and components that can be enabled/disabled..
         $boot = HTML_FlexyFramework::get();

        // the modules call just lists the modules
        $enabled =  array('Core' => true);
         $am = !empty($boot->enable) ? explode(',',  $boot->enable) : array();
        foreach($am as $k) {
            if (strpos( $k ,'.') ) {
                continue;
            }
            $enabled[$k] = true;
        }
        
        
        $disabled =  !empty($boot->disable) ?  explode(',', $boot->disable) : array();
        foreach($disabled as $k) {
            if ( strpos( $k ,'.') ) {
                continue;
            }
            if (isset($enabled[$k])) {
                unset($enabled[$k]);
            }   
        }
         //echo '<PRE>';       var_Dump($enabled);

        return array_keys($enabled); 
    }
    
    function hasModule($name) 
    {
        $this->init();
        if (!strpos( $name,'.') ) {
            // use enable / disable..
            return in_array($name, $this->modules()); 
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
     * jerrAuth: standard auth failure - with data that let's the UI know..
     */
    function jerrAuth()
    {
        $this->jerr("Not authenticated", array('authFailure' => true));
    }
     
    /**
     * ---------------- Standard JSON outputers. - used everywhere
     */
    
    function jerr($str, $errors=array()) // standard error reporting..
    {
        
        $cli = HTML_FlexyFramework::get()->cli;
        if ($cli) {
            echo "ERROR:\n" .$str . "\n";
            exit;
        }
        
        require_once 'Services/JSON.php';
        $json = new Services_JSON();
        
        // log all errors!!!
        $this->addEvent("ERROR", false, $str);
        
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
            // encode html characters so they can be read..
            echo  str_replace(array('<','>'), array('\u003c','\u003e'),
                        $json->encodeUnsafe(array('success'=> true, 'data' => $str)));
            echo "</BODY></HTML>";
            exit;
        }
         
        
        echo  $json->encode(array('success'=> true, 'data' => $str));
        exit;
        
    }
    /**
     * output data for grids or tree
     * @ar {Array} ar Array of data
     * @total {Number|false} total number of records (or false to return count(ar)
     * @extra {Array} extra key value list of data to pass as extra data.
     * 
     */
    function jdata($ar,$total=false, $extra=array())
    {
        // should do mobile checking???
        if ($total == false) {
            $total = count($ar);
        }
        $extra=  $extra ? $extra : array();
        require_once 'Services/JSON.php';
        $json = new Services_JSON();
        if (isset($_SERVER['CONTENT_TYPE']) && preg_match('#multipart/form-data#i', $_SERVER['CONTENT_TYPE'])) {
            
            header('Content-type: text/html');
            echo "<HTML><HEAD></HEAD><BODY>";
            // encode html characters so they can be read..
            echo  str_replace(array('<','>'), array('\u003c','\u003e'),
                        $json->encodeUnsafe(array('success' =>  true, 'total'=> $total, 'data' => $ar) + $extra));
            echo "</BODY></HTML>";
            exit;
        }
        
      
        
        
        
        
       
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
        if (in_array('Core',$mods)) { // core has to be the first  modules loaded as it contains Pman.js
            array_unshift($mods,   'Core');
        }
        
        $mods = array_unique($mods);
         
        $disabled =  explode(',', $this->appDisable ? $this->appDisable: '');
        
        foreach($mods as $mod) {
            // add the css file..
            if (in_array($mod, $disabled)) {
                continue;
            }
            
            
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
    /**
     * packJS:
     *
     * @file path to 
     *
     *
     */
    
     function packJS($dir)
    {
       
        // target has to be 'aliased'
        // target filename can be an md5..
        
        require_once 'Pman/Core/JsCompile.php';
        $x = new Pman_Core_JsCompile();
        $x->packScript(dirname(__FILE__).'/Hex/templates/images', 
                       array($dir),
                        $this->rootURL . '/Hex/templates/images');
        
        
    }
    /**
     * Gather infor for javascript files..
     *
     * @param {String} $mod the module to get info about.
     * @return {StdClass}  details about module.
     */
    function moduleJavascriptFilesInfo($mod)
    {
        $ff = HTML_FlexyFramework::get();
        
        $base = dirname($_SERVER['SCRIPT_FILENAME']);
        $dir =   $this->rootDir.'/Pman/'. $mod;
            
        
        $ar = glob($dir . '/*.js');
        
        $files = array();
        $arfiles = array();
        $maxtime = 0;
        $mtime = 0;
        foreach($ar as $fn) {
            $f = basename($fn);
            // got the 'module file..'
            $mtime = filemtime($dir . '/'. $f);
            $maxtime = max($mtime, $maxtime);
            $arfiles[$fn] = $mtime;
            $files[] = $path . $f . '?ts='.$mtime;
        }
        
        ksort($arfiles); // just sort by name so it's consistant for serialize..
        
        $compile  = empty($ff->Pman['public_cache_dir']) ? 0 : 1;
        $basedir = $ff->Pman['public_cache_dir'];
        $baseurl = $ff->Pman['public_cache_url'];
        
        $lsort = create_function('$a,$b','return strlen($a) > strlen($b) ? 1 : -1;');
        usort($files, $lsort);
        
        $output = date('Y-m-d-H-i-s-', $maxtime). str_replace('/','.',$mod).'-'.md5(serialize($arfiles)) .'.js';
        
        return (object) array(
            'files' => $files,
            'filesmtime' => $arfiles,
            'maxtime' => $maxtime,
            'compile' => $compile,
            'translation_file' =>  realpath($base .'/_translations_/' . str_replace('/','.', $mod) .  '.js'),
            'output' => $output,
            'translation_data' => preg_replace('/\.js$/', '.__translation__.js', $output),
            'translation_base' => dirname($dir),
            'basedir' => $basedir,
            'baseurl' => $baseurl,
        );
    }
    
    
    
    
    /**
     *  moduleJavascriptList: list the javascript files in a module
     *
     *  The original version of this.. still needs more thought...
     *
     *  Compiled is in Pman/_compiled_/{$mod}/{LATEST...}.js
     *  Translations are in Pman/_translations_/{$mod}.js
     *  
     *  if that stuff does not exist just list files in  Pman/{$mod}/*.js
     *
     *  Compiled could be done on the fly..
     * 
     *
     *
     *  @param {String} $mod  the module to look at - eg. Pman/{$mod}/*.js
     *  @return {Array} list of include paths (either compiled or raw)
     *
     */

    
    
    function moduleJavascriptList($mod)
    {
        
        
        $dir =   $this->rootDir.'/Pman/'. $mod;
        if (!file_exists($dir)) {
            return array();
        }
        
        $info = $this->moduleJavascriptFilesInfo($mod);
        
        
          
        if (empty($info->files)) {
            return array();
        }
        // finally sort the files, so they are in the right order..
        
        // only compile this stuff if public_cache is set..
        
        $compile = empty($ff->Pman['public_cache_dir']) ? 0 : 1;
        
        // suggestions...
        //  public_cache_dir =   /var/www/myproject_cache
        //  public_cache_url =   /myproject_cache    (with Alias apache /myproject_cache/ /var/www/myproject_cache/)
       
          
        // where are we going to write all of this..
        // This has to be done via a 
        if ( $info->compile && !file_exists($info->basedir.'/'.$info->output)) {
            require_once 'Pman/Core/JsCompile.php';
            $x = new Pman_Core_JsCompile();
            
            $x->pack($info->filesmtime,$info->basedir.'/'.$info->output, $info->translation_base);
        }
        
        if ($info->compile && file_exists($info->basedir.'/'.$info->output) && filesize($info->basedir.'/'.$info->output)) {
            
            return array(
                $info->baseurl.'/'. $info->output,
                $this->rootURL."/_translations_/". str_replace('/','.', $mod).".js"
            );
        }
        
        
        
        // give up and output original files...
        
         
        return $files;

        
    }
    
    
    
    /**
     * ---------------- Logging ---------------   
     */
    
    /**
     * addEventOnce:
     * Log an action (only if it has not been logged already.
     * 
     * @param {String} action  - group/name of event
     * @param {DataObject|false} obj - dataobject action occured on.
     * @param {String} any remarks 
     */
    
    function addEventOnce($act, $obj = false, $remarks = '') 
    {
        $au = $this->getAuthUser();
        $e = DB_DataObject::factory('Events');
        $e->init($act,$obj,$remarks); 
        if ($e->find(true)) {
            return;
        }
        return $this->addEvent($act, $obj, $remarks);
    }
    /**
     * addEvent:
     * Log an action.
     * 
     * @param {String} action  - group/name of event
     * @param {DataObject|false} obj - dataobject action occured on.
     * @param {String} any remarks
     * @return {Number} Event id.,
     */
    
    function addEvent($act, $obj = false, $remarks = '') 
    {
        $au = $this->getAuthUser();
        $e = DB_DataObject::factory('Events');
        $e->init($act,$obj,$remarks); 
         
        $e->event_when = date('Y-m-d H:i:s');
        
        $eid = $e->insert();
        $ff  = HTML_FlexyFramework::get();
        if (empty($ff->Pman['event_log_dir'])) {
            return $eid;
        }
        $file = $ff->Pman['event_log_dir']. date('/Y/m/d/'). $eid . ".php";
        if (!file_exists(dirname($file))) {
            mkdir(dirname($file),0700,true);
        }
        file_put_contents($file, var_export(array(
            'REQUEST_URI' => empty($_SERVER['REQUEST_URI']) ? 'cli' : $_SERVER['REQUEST_URI'],
            'GET' => empty($_GET) ? array() : $_GET,
            'POST' => empty($_POST) ? array() : $_POST,
        ), true));
        
        return $eid;
        
    }

    
     
    
}
