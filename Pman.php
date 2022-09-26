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
 *  OPTIONS
 *  Pman['local_autoauth']   // who to login as when using localhost
 *  Pman['isDev']  // can the site show develpment info.?
 *  Pman['uiConfig']  // extra variable to export to front end..
 *  Pman['auth_comptype'] // -- if set to 'OWNER' then only users with company=OWNER can log in
 *  Pman['authTable'] // the authentication table (default 'person')
 *
 * 
 * Usefull implemetors
 * DB_DataObject*:*toEventString (for logging - this is generically prefixed to all database operations.)
 *   - any data object where this method exists, the result will get prefixed to the log remarks
 */

 
     
 
require_once 'Pman/Core/AssetTrait.php';

class Pman extends HTML_FlexyFramework_Page 
{
    use Pman_Core_AssetTrait;
    //outputJavascriptDir()
    //outputCssDir();
    var $isDev = false;
    var $appName= "";
    var $appLogo= "";
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
    
    var $disable_jstemplate = false; /// disable inclusion of jstemplate code..
    var $company = false;
    
    var $css_path = ''; // can inject a specific path into the base HTML page.
    
    
    var $transObj = false; // used to rollback or commit in JOK/JERR
    
    // these are used somewhere - 
    var $builderJs = false;//
    var $serverName = false;
    var $lang = false;
    var $allowSignup = false;
    
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
    
    function init($base = false) 
    {
        
        if (isset($this->_hasInit)) {
            return;
        }
        $this->_hasInit = true;
         // move away from doing this ... you can access bootLoader.XXXXXX in the master template..
        $boot = HTML_FlexyFramework::get();
        // echo'<PRE>';print_R($boot);exit;
        $this->appName      = $boot->appName;
        
        $this->appNameShort = $boot->appNameShort;
        
        $this->appModules   = $boot->enable;
        
//        echo $this->arrayToJsInclude($files);        
        $this->isDev = empty($boot->Pman['isDev']) ? false : $boot->Pman['isDev'];
        
        $this->css_path = empty($boot->Pman['css_path']) ? '' : $boot->Pman['css_path'];
        
        $this->appDisable = $boot->disable;
        $this->appDisabled = explode(',', $boot->disable);
        $this->version = $boot->version; 
        $this->uiConfig = empty($boot->Pman['uiConfig']) ? false : $boot->Pman['uiConfig']; 
        
        if (!empty($boot->Pman['local_autoauth']) &&
            !empty($_SERVER['SERVER_ADDR']) &&
            !empty($_SERVER['REMOTE_ADDR']) &&            
            ($_SERVER['SERVER_ADDR'] == '127.0.0.1') &&
            ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') 
        ) {
            $this->isDev = true;
        }
        
        if (
            !empty($_REQUEST['isDev'])
            &&
            (
                (
                    !empty($_SERVER['SERVER_ADDR']) &&
                    (
                        (($_SERVER['SERVER_ADDR'] == '127.0.0.1') && ($_SERVER['REMOTE_ADDR'] == '127.0.0.1'))
                        ||
                        (($_SERVER['SERVER_ADDR'] == '::1') && ($_SERVER['REMOTE_ADDR'] == '::1'))
                        ||
                        (preg_match('/^192\.168/', $_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] == $_SERVER['HTTP_HOST'])
                    )
                )
                ||
                !empty($boot->Pman['enable_isdev_url'])
            )
            
        ) {
            $boot->Pman['isDev'] = true;
            $this->isDev = true;
        }
        
        // if a file Pman_{module}_Pman exists.. and it has an init function... - call that..
        
        //var_dump($this->appModules);
        
        
        
    }
    /*
     * call a method on {module}/Pman.php
     * * initially used on the main page load to call init();
     * * also used for ccsIncludes?? 
     *
     * // usage: $this->callModules('init', $base)
     * 
     */
     
    function callModules($fn) 
    {
        $args = func_get_args();
        array_shift($args);
        foreach(explode(',',$this->appModules) as $m) {
            $cls = 'Pman_'. $m . '_Pman';
            if (!file_exists($this->rootDir . '/'.str_replace('_','/', $cls). '.php')) {
                continue;
            }
            require_once str_replace('_','/', $cls). '.php';
            $c = new $cls();
            if (method_exists($c, $fn)) {
                call_user_func_array(array($c,$fn),$args);
            }
        }
         
     }
    
    function get($base, $opts=array()) 
    {
        $this->init();
        if (empty($base)) {
            $this->callModules('init', $this, $base);
        }
        
            //$this->allowSignup= empty($opts['allowSignup']) ? 0 : 1;
        $bits = explode('/', $base);
      
        
        // should really be moved to Login...
        /*
        if ($bits[0] == 'PasswordReset') {
            $this->linkFail = $this->resetPassword(@$bits[1],@$bits[2],@$bits[3]);
            header('Content-type: text/html; charset=utf-8');
            return;
        }
        */
         
        $au = $this->getAuthUser();
        if ($au) {
            $ff= HTML_FlexyFramework::get();
           
            if (!empty($ff->Pman['auth_comptype']) && $au->id > 0 &&
                ( !$au->company_id || ($ff->Pman['auth_comptype'] != $au->company()->comptype))) {
         
                $au->logout();
                
                $this->jerr("Login not permited to outside companies - please reload");
            }
            $this->addEvent("RELOAD");
        }
        
        
        if (strlen($base) && $bits[0] != 'PasswordReset') {
            $this->jerror("BADURL","invalid url: $base");
        }
        // deliver template
        if (isset($_GET['onloadTrack'])) {
            $this->onloadTrack = (int)$_GET['onloadTrack'];
        }
        // getting this to work with xhtml is a nightmare
        // = nbsp / <img> issues screw everyting up.
         //var_dump($this->isDev);
        // force regeneration on load for development enviroments..
        
        HTML_FlexyFramework::get()->generateDataobjectsCache($this->isDev && !empty($_REQUEST['isDev']));
        
        //header('Content-type: application/xhtml+xml; charset=utf-8');
        
        
        
        if ($this->company && $this->company->logo_id) {
            $im = DB_DataObject::Factory('Images');
            $im->get($this->company->logo_id);
            $this->appLogo = $this->baseURL . '/Images/Thumb/x100/'. $this->company->logo_id .'/' . $im->filename;
        }
        
        header('Content-type: text/html; charset=utf-8');
         
    }
    function post($base) {
        return $this->get($base);
    }
    
    
    // --------------- AUTHENTICATION or  system information
    /**
     * loadOwnerCompany:
     * finds the compay with comptype=='OWNER'
     * ?? what about comptype_id-name ?????
     *
     * @return {Pman_Core_DataObjects_Companies} the owner company
     */
    function loadOwnerCompany()
    {
        // only applies if authtable is person..
        $ff = HTML_FlexyFramework::get();
        if (!empty($ff->Pman['authTable']) && !in_array($ff->Pman['authTable'] , [ 'core_person', 'Person' ])) {
            return false;
        }
        
        $this->company = DB_DataObject::Factory('core_company');
        if (!is_a($this->company, 'DB_DataObject')) { // non-core pman projects
            return false; 
        }
        $e = DB_DataObject::Factory('core_enum')->lookupObject('COMPTYPE', 'OWNER');

        $this->company->get('comptype_id', $e->id);
        return $this->company;
    }
    
    
    static function staticGetAuthUser($t) {
        if (!empty($t->authUser)) {
            return $t->authUser;
        }
        $ff = HTML_FlexyFramework::get();
        $tbl = empty($ff->Pman['authTable']) ? 'core_person' : $ff->Pman['authTable'];
        
        $u = DB_DataObject::factory( $tbl );
        
        if (is_a($u,'PEAR_Error') || !$u->isAuth()) {
            return false;
        }
        $t->authUser =$u->getAuthUser();
        return $t->authUser ;
        
    }
    
    /**
     * getAuthUser: - get the authenticated user..
     *
     * @return {DB_DataObject} of type Pman[authTable] if authenticated.
     */
    
    function getAuthUser()
    {
        return self::staticGetAuthUser($this);
    }
    /**
     * hasPerm:
     * wrapper arround authuser->hasPerm
     * @see Pman_Core_DataObject_Core_person::hasPerm
     *
     * @param {String} $name  The permission name (eg. Projects.List)
     * @param {String} $lvl   eg. (C)reate (E)dit (D)elete ... etc.
     * 
     */
    function hasPerm($name, $lvl)  // do we have a permission
    {
        static $pcache = array();
        $au = $this->getAuthUser();
        return $au && $au->hasPerm($name,$lvl);
        
    }   
    /**
     * modulesList:  List the modules in the application
     *
     * @return {Array} list of modules
     */
    function modulesList()
    {
        $boot = HTML_FlexyFramework::get();
        // echo'<PRE>';print_R($boot);exit;
         
         
        $mods = explode(',', $boot->enable);
        if (in_array('Core',$mods)) { // core has to be the first  modules loaded as it contains Pman.js
            array_unshift($mods,   'Core');
        }
        
        if (in_array($boot->appNameShort,$mods)) { // Project has to be the last  modules loaded as it contains Pman.js
            unset($mods[array_search($boot->appNameShort, $mods)]);
            $mods[] = $boot->appNameShort;
        }
        
        $mods = array_unique($mods);
         
        $disabled =  explode(',', $boot->disable ? $boot->disable : '');
        $ret = array();
        foreach($mods as $mod) {
            // add the css file..
            if (in_array($mod, $disabled)) {
                continue;
            }
            $ret[] = $mod;
        }
        return $ret;
    }
    
     
    
    
    function hasModule($name) 
    {
        $this->init();
        if (!strpos( $name,'.') ) {
            // use enable / disable..
            return in_array($name, $this->modules()); 
        }
        
        $x = DB_DataObject::factory('core_group_right');
        $ar = $x->defaultPermData();
        if (empty($ar[$name]) || empty($ar[$name][0])) {
            return false;
        }
        return true;
    }
    
     
    
    function jsencode($v, $header = false)
    {
        if ($header) {
            header("Content-type: text/javascript");
        }
        if (function_exists("json_encode")) {
            $ret=  json_encode($v);
            if ($ret !== false) {
                return $ret;
            }
        }
        require_once 'Services/JSON.php';
        $js = new Services_JSON();
        return $js->encodeUnsafe($v);
        
        
        
    }

     
        
    /**
     * ---------------- Global Tools ---------------   
     */
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
    
    static $deleteOnExit = false;
    /**
     * generate a tempory file with an extension (dont forget to delete it)
     */
    
    function deleteOnExitAdd($name)
    {
        if (self::$deleteOnExit === false) {
            register_shutdown_function(array('Pman','deleteOnExit'));
            self::$deleteOnExit  = array();
        }
        self::$deleteOnExit[] = $name;
    }
    
    function tempName($ext, $deleteOnExit=false)
    {
        
        $x = tempnam(ini_get('session.save_path'), HTML_FlexyFramework::get()->appNameShort.'TMP');
        unlink($x);
        $ret = $x .'.'. $ext;
        if ($deleteOnExit) {
            $this->deleteOnExitAdd($ret);
        }
        return $ret;
    
    }
   
     static function deleteOnExit()
    {
        
        foreach(self::$deleteOnExit as $fn) {
            if (file_exists($fn)) {
                unlink($fn);
            }
        }
    }
    
    /**
     * ------------- Authentication password reset ------ ??? MOVEME?
     * 
     * 
     */
    
    
    
    /**
     * jerrAuth: standard auth failure - with data that let's the UI know..
     */
    function jerrAuth()
    {
        $au = $this->authUser();
        if ($au) {
            // is it an authfailure?
            $this->jerr("Permission denied to view this resource", array('authFailure' => true));
        }
        $this->jerr("Not authenticated", array('authFailure' => true));
    }
     
     
     
    /**
     * ---------------- Standard JSON outputers. - used everywhere
     */
      /**
     * ---------------- Standard JSON outputers. - used everywhere
     * JSON error - simple error with logging.
     * @see Pman::jerror
     */
    
    function jerr($str, $errors=array(), $content_type = false) // standard error reporting..
    {
        return $this->jerror('ERROR', $str,$errors,$content_type);
    }
    /**
     * Recomended JSON error indicator
     *
     * 
     * @param string $type  - normally 'ERROR' - you can use this to track error types.
     * @param string $message - error message displayed to user.
     * @param array $errors - optioanl data to pass to front end.
     * @param string $content_type - use text/plain to return plan text - ?? not sure why...
     *
     */
    
    function jerror($type, $str, $errors=array(), $content_type = false) // standard error reporting..
    {
        if ($this->transObj) {
            $this->transObj->query('ROLLBACK');
        }
        
        $cli = HTML_FlexyFramework::get()->cli;
        if ($cli) {
            echo "ERROR: " .$str . "\n"; // print the error first, as DB might fail..
        }
        
        if ($type !== false) {
            
            if(!empty($errors)){
                DB_DataObject::factory('Events')->writeEventLogExtra($errors);
            }
            
            $this->addEvent($type, false, $str);
            
        }
         
        $cli = HTML_FlexyFramework::get()->cli;
        if ($cli) {
            exit(1); // cli --- exit code to stop shell execution if necessary.
        }
        
        
        if ($content_type == 'text/plain') {
            header('Content-Disposition: attachment; filename="error.txt"');
            header('Content-type: '. $content_type);
            echo "ERROR: " .$str . "\n";
            exit;
        } 
        
     // log all errors!!!
        
        $retHTML = isset($_SERVER['CONTENT_TYPE']) && 
                preg_match('#multipart/form-data#i', $_SERVER['CONTENT_TYPE']);
        
        if ($retHTML){
            if (isset($_REQUEST['returnHTML']) && $_REQUEST['returnHTML'] == 'NO') {
                $retHTML = false;
            }
        } else {
            $retHTML = isset($_REQUEST['returnHTML']) && $_REQUEST['returnHTML'] !='NO';
        }
        
        
        if ($retHTML) {
            header('Content-type: text/html');
            echo "<HTML><HEAD></HEAD><BODY>";
            echo  $this->jsencode(array(
                    'success'=> false, 
                    'errorMsg' => $str,
                    'message' => $str, // compate with exeption / loadexception.

                    'errors' => $errors ? $errors : true, // used by forms to flag errors.
                    'authFailure' => !empty($errors['authFailure']),
                ), false);
            echo "</BODY></HTML>";
            exit;
        }
        
        if (isset($_REQUEST['_debug'])) {
            echo '<PRE>'.htmlspecialchars(print_r(array(
                'success'=> false, 
                'data'=> array(), 
                'errorMsg' => $str,
                'message' => $str, // compate with exeption / loadexception.
                'errors' => $errors ? $errors : true, // used by forms to flag errors.
                'authFailure' => !empty($errors['authFailure']),
            ),true));
            exit;
                
        }
        
        echo $this->jsencode(array(
            'success'=> false, 
            'data'=> array(),
            'code' => $type,
            'errorMsg' => $str,
            'message' => $str, // compate with exeption / loadexception.
            'errors' => $errors ? $errors : true, // used by forms to flag errors.
            'authFailure' => !empty($errors['authFailure']),
        ),true);
        
        
        exit;
        
    }
    function jok($str)
    {
        if ($this->transObj ) {
            $this->transObj->query( connection_aborted() ? 'ROLLBACK' :  'COMMIT');
        }
        
        $cli = HTML_FlexyFramework::get()->cli;
        if ($cli) {
            echo "OK: " .$str . "\n";
            exit;
        }
        
        $retHTML = isset($_SERVER['CONTENT_TYPE']) && 
                preg_match('#multipart/form-data#i', $_SERVER['CONTENT_TYPE']);
        
        if ($retHTML){
            if (isset($_REQUEST['returnHTML']) && $_REQUEST['returnHTML'] == 'NO') {
                $retHTML = false;
            }
        } else {
            $retHTML = isset($_REQUEST['returnHTML']) && $_REQUEST['returnHTML'] !='NO';
        }
        
        if ($retHTML) {
            header('Content-type: text/html');
            echo "<HTML><HEAD></HEAD><BODY>";
            // encode html characters so they can be read..
            echo  str_replace(array('<','>'), array('\u003c','\u003e'),
                        $this->jsencode(array('success'=> true, 'data' => $str), false));
            echo "</BODY></HTML>";
            exit;
        }
        
        
        echo  $this->jsencode(array('success'=> true, 'data' => $str),true);
        
        exit;
        
    }
    /**
     * output data for grids or tree
     * @ar {Array} ar Array of data
     * @total {Number|false} total number of records (or false to return count(ar)
     * @extra {Array} extra key value list of data to pass as extra data.
     * 
     */
    function jdata($ar,$total=false, $extra=array(), $cachekey = false)
    {
        // should do mobile checking???
        if ($total == false) {
            $total = count($ar);
        }
        $extra=  $extra ? $extra : array();
        
        
        $retHTML = isset($_SERVER['CONTENT_TYPE']) && 
                preg_match('#multipart/form-data#i', $_SERVER['CONTENT_TYPE']);
        
        if ($retHTML){
            if (isset($_REQUEST['returnHTML']) && $_REQUEST['returnHTML'] == 'NO') {
                $retHTML = false;
            }
        } else {
            $retHTML = isset($_REQUEST['returnHTML']) && $_REQUEST['returnHTML'] !='NO';
        }
        
        if ($retHTML) {
            
            header('Content-type: text/html');
            echo "<HTML><HEAD></HEAD><BODY>";
            // encode html characters so they can be read..
            echo  str_replace(array('<','>'), array('\u003c','\u003e'),
                        $this->jsencode(array('success' =>  true, 'total'=> $total, 'data' => $ar) + $extra, false));
            echo "</BODY></HTML>";
            exit;
        }
        
        
        // see if trimming will help...
        if (!empty($_REQUEST['_pman_short'])) {
            $nar = array();
            
            foreach($ar as $as) {
                $add = array();
                foreach($as as $k=>$v) {
                    if (is_string($v) && !strlen(trim($v))) {
                        continue;
                    }
                    $add[$k] = $v;
                }
                $nar[] = $add;
            }
            $ar = $nar;
              
        }
        
      
        $ret =  $this->jsencode(array('success' =>  true, 'total'=> $total, 'data' => $ar) + $extra,true);  
        
        if (!empty($cachekey)) {
            
            $fn = ini_get('session.save_path') . '/json-cache'.date('/Y/m/d').'.'. $cachekey . '.cache.json';
            if (!file_exists(dirname($fn))) {
                mkdir(dirname($fn), 0777,true);
            }
            file_put_contents($fn, $ret);
        }
        
        echo $ret;
        exit;
    }
    
    
    
    /** a daily cache **/
    function jdataCache($cachekey)
    {
        $fn = ini_get('session.save_path') . '/json-cache'.date('/Y/m/d').'.'. $cachekey . '.cache.json';
        if (file_exists($fn)) {
            header('Content-type: application/json');
            echo file_get_contents($fn);
            exit;
        }
        return false;
        
    }
    
   
    
    /**
     * ---------------- OUTPUT
     */
    function hasBg($fn) // used on front page to check if logos exist..
    {
        return file_exists($this->rootDir.'/Pman/'.$this->appNameShort.'/templates/images/'.  $fn);
    }
     /**
     * outputJavascriptIncludes:
     *
     * output <script....> for all the modules in the applcaiton
     *
     */
    function outputJavascriptIncludes()  
    {
        // BC support - currently 1 project still relies on this.. (MO portal)
        $ff = HTML_FlexyFramework::get();
        $o = isset($ff->Pman_Core)  ? $ff->Pman_Core : array();
        if (isset($o['packseed'])) {
            return $this->outputJavascriptIncludesBC();
        }
        
        
        $mods = $this->modulesList();
        
        $is_bootstrap = in_array('BAdmin', $mods);
        
        foreach($mods as $mod) {
            // add the css file..
            
            if ($is_bootstrap) {
                if (!file_exists($this->rootDir."/Pman/$mod/is_bootstrap")) {
                    echo '<!-- missing '. $this->rootDir."/Pman/$mod/is_bootstrap  - skipping -->";
                    continue;
                }
                
            }
        
            $this->outputJavascriptDir("Pman/$mod/widgets", "*.js");
            $this->outputJavascriptDir("Pman/$mod", "*.js");
            
        }
        
        if (empty($this->disable_jstemplate)) {
        // and finally the JsTemplate...
            echo '<script type="text/javascript" src="'. $this->baseURL. '/Core/JsTemplate"></script>'."\n";
        }
        
        $this->callModules('outputJavascriptIncludes', $this);
        return '';
    }
    var $css_includes = array();
     /**
     * outputCSSIncludes:
     *
     * output <link rel=stylesheet......> for all the modules in the applcaiton
     *
     *
     * This could css minify as well.
     */
    function outputCSSIncludes() // includes on CSS links.
    {
       
        
        $mods = $this->modulesList();
        $is_bootstrap = in_array('BAdmin', $mods);

        $this->callModules('applyCSSIncludes', $this);
        foreach($this->css_includes as $module => $ar) {
            
            if ($ar) {
                $this->assetArrayToHtml( $ar , 'css');
            }
        }
        
        // old style... - probably remove this...
        $this->callModules('outputCSSIncludes', $this);
        
        foreach($mods as $mod) {
            // add the css file..
            if ($is_bootstrap  && !file_exists($this->rootDir."/Pman/$mod/is_bootstrap")) {
                echo '<!-- missing '. $this->rootDir."/Pman/$mod/is_bootstrap  - skipping -->";
                continue;
            }
            $this->outputCSSDir("Pman/$mod","*.css");
           
            $this->outputSCSS($mod);
            
            
        }
        return ''; // needs to return something as we output it..
        
    }
    
    
    
    
    
    
    
    
     
    
    // --- OLD CODE - in for BC on MO project.... - needs removing...
    
    // used on old versions.....
    function outputJavascriptIncludesBC()  
    {
        
        $mods = $this->modulesList();
        
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
        if (empty($this->disable_jstemplate)) {
        // and finally the JsTemplate...
            echo '<script type="text/javascript" src="'. $this->baseURL. '/Core/JsTemplate"></script>'."\n";
        }
        return '';
    }
    /**
     * Gather infor for javascript files..
     *
     * @param {String} $mod the module to get info about.
     * @return {StdClass}  details about module.
     */
    function moduleJavascriptFilesInfo($mod)
    {
        
        static $cache = array();
        
        if (isset($cache[$mod])) {
            return $cache[$mod];
        }
        
        
        $ff = HTML_FlexyFramework::get();
        
        $base = dirname($_SERVER['SCRIPT_FILENAME']);
        $dir =   $this->rootDir.'/Pman/'. $mod;
        $path = $this->rootURL ."/Pman/$mod/";
        
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
        
        // The original idea of this was to serve the files direct from a publicly available 'cache' directory.
        // but that doesnt really make sense - as we can just serve it from the session directory where we stick
        // cached data anyway.
        
        /*
        $compile  = empty($ff->Pman['public_cache_dir']) ? 0 : 1;
        $basedir = $compile ? $ff->Pman['public_cache_dir'] : false;
        $baseurl = $compile ? $ff->Pman['public_cache_url'] : false;
        */
       
        $compile = 1;
        $basedir = session_save_path().   '/translate-cache/';
        if (!file_exists($basedir)) {
            mkdir($basedir,0755);
        }
        $baseurl = $this->baseURL .  '/Admin/Translations';
        
        if (PHP_VERSION_ID < 70000 ) {
            $lsort = create_function('$a,$b','return strlen($a) > strlen($b) ? 1 : -1;');
            usort($files, $lsort);
        } else {
            usort($files, function($a,$b) { return strlen($a) > strlen($b) ? 1 : -1; });
        }
        
        $smod = str_replace('/','.',$mod);
        
        $output = date('Y-m-d-H-i-s-', $maxtime). $smod .'-'.md5(serialize($arfiles)) .'.js';
        
        
        // why are translations done like this - we just build them on the fly frmo the database..
        $tmtime = file_exists($this->rootDir.'/_translations_/'. $smod.'.js')
            ? filemtime($this->rootDir.'/_translations_/'. $smod.'.js') : 0;
        
        $cache[$mod]  = (object) array(
            'smod' =>               $smod, // module name without '/'
            'files' =>              $files, // list of all files.
            'filesmtime' =>         $arfiles,  // map of mtime=>file
            'maxtime' =>            $maxtime, // max mtime
            'compile' =>            $this->isDev ? false : $compile,
            'translation_file' =>   $base .'/_translations_/' . $smod .  '.js',
            'translation_mtime' =>  $tmtime,
            'output' =>             $output,
            'translation_data' =>   preg_replace('/\.js$/', '.__translation__.js', $output),
            'translation_base' =>   $dir .'/', //prefix of filename (without moudle name))
            'basedir' =>            $basedir,   
            'baseurl' =>            $baseurl,
            'module_dir' =>         $dir,  
        );
        return $cache[$mod];
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
            echo '<!-- missing directory '. htmlspecialchars($dir) .' -->';
            return array();
        }
        
        $info = $this->moduleJavascriptFilesInfo($mod);
       
        
          
        if (empty($info->files)) {
            return array();
        }
        // finally sort the files, so they are in the right order..
        
        // only compile this stuff if public_cache is set..
        
         
        // suggestions...
        //  public_cache_dir =   /var/www/myproject_cache
        //  public_cache_url =   /myproject_cache    (with Alias apache /myproject_cache/ /var/www/myproject_cache/)
        
        // bit of debugging
        if (!$info->compile) {
            echo "<!-- Javascript compile turned off (isDev on, or public_cache_dir not set) -->\n";
            return $info->files;
        }
        
        // where are we going to write all of this..
        // This has to be done via a 
        if (!file_exists($info->basedir.'/'.$info->output) || !filesize($info->basedir.'/'.$info->output)) {
            require_once 'Pman/Core/JsCompile.php';
            $x = new Pman_Core_JsCompile();
            
            $x->pack($info->filesmtime,$info->basedir.'/'.$info->output, $info->translation_base);
        } else {
            echo "<!-- file exists not exist: {$info->basedir}/{$info->output} -->\n";
        }
        
        if (file_exists($info->basedir.'/'.$info->output) &&
                filesize($info->basedir.'/'.$info->output)) {
            
            $ret =array(
                $info->baseurl.'/'. $info->output,
              
            );
            // output all the ava
            // fixme  - this needs the max datetime for the translation file..
            $ret[] = $this->baseURL."/Admin/InterfaceTranslations/".$mod.".js"; //?ts=".$info->translation_mtime;
            
            //if ($info->translation_mtime) {
            //    $ret[] = $this->rootURL."/_translations_/". $info->smod.".js?ts=".$info->translation_mtime;
            //}
            return $ret;
        }
        
        
        
        // give up and output original files...
        
         
        return $info->files;

        
    }
    
    /**
     * Error handling...
     *  PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, array($this, 'onPearError'));
     */
    function initErrorHandling()
    {
        if (!class_exists('HTML_FlexyFramework2')) {
            // what about older code that still users PEAR?
            PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, array($this, 'onPearError'));
        }
        set_exception_handler(array($this,'onException'));
        
    }
    
    
    static $permitError = false; // static why?
    
    var $showErrorToUser = true;
    
    function onPearError($err)
    {
        return $this->onException($err);
        
    }
    
    
    function onException($ex)
    {
         static $reported = false;
        if ($reported) {
            return;
        }
        
        if (Pman::$permitError) {
            return;
        }
        
        
        $reported = true;
        
        $out = is_a($ex,'Exception') || is_a($ex, 'Error') ? $ex->getMessage() : $ex->toString();
        
        
        //print_R($bt); exit;
        $ret = array();
        $n = 0;
        $bt = is_a($ex,'Exception')|| is_a($ex, 'Error')  ? $ex->getTrace() : $ex->backtrace;
        if (is_a($ex,'Exception')|| is_a($ex, 'Error') ) {
            $ret[] = $ex->getFile() . '('. $ex->getLine() . ')';
        }
        foreach( $bt as $b) {
            $ret[] = @$b['file'] . '(' . @$b['line'] . ')@' .   @$b['class'] . '::' . @$b['function'];
            if ($n > 20) {
                break;
            }
            $n++;
        }
        //convert the huge backtrace into something that is readable..
        $out .= "\n" . implode("\n",  $ret);
        
        $this->addEvent("EXCEPTION", false, $out);
        
        if ($this->showErrorToUser) {
            print_R($out);exit;
        }
        // not sure why this is here... - perhaps doing a jerr() was actually caught by the UI, and hidden from the user..?
        $this->jerror(false,"An error Occured, please contact the website owner");
        
        //$this->jerr($out);
        
        
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
     * @return {false|DB_DataObject} Event object.,
     */
    
    function addEventOnce($act, $obj = false, $remarks = '') 
    {
        if (!empty(HTML_FlexyFramework::get()->Pman['disable_events'])) {
            return;
        }
        $e = DB_DataObject::factory('Events');
        $e->init($act,$obj,$remarks); 
        if ($e->find(true)) {
            return false;
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
     * @return {DB_DataObject} Event object.,
     */
    
    function addEvent($act, $obj = false, $remarks = '') 
    {
        
        if (!empty(HTML_FlexyFramework::get()->Pman['disable_events'])) {
            return;
        }
        $au = $this->getAuthUser();
       
        $e = DB_DataObject::factory('Events');
        $e->init($act,$obj,$remarks); 
         
        $e->event_when = $e->sqlValue('NOW()');
        
        $eid = $e->insert();
        
        // fixme - this should be in onInsert..
        $wa = DB_DataObject::factory('core_watch');
        if (method_exists($wa,'notifyEvent')) {
            $wa->notifyEvent($e); // trigger any actions..
        }
        
        
        $e->onInsert(isset($_REQUEST) ? $_REQUEST : array() , $this);
        
       
        return $e;
        
    }
    
    function addEventNotifyOnly($act, $obj = false, $remarks = '')
    {
         $au = $this->getAuthUser();
       
        $e = DB_DataObject::factory('Events');
        $e->init($act,$obj,$remarks); 
         
        $e->event_when = $e->sqlValue('NOW()');
        $wa = DB_DataObject::factory('core_watch');
        if (method_exists($wa,'notifyEvent')) {
            $wa->notifyEvent($e); // trigger any actions..
        }
    }
    
    
    // ------------------ DEPERCIATED ----------------------------
     
    // DEPRECITAED - use moduleslist
    function modules()  { return $this->modulesList();  }
    
   
    // DEPRICATED  USE Pman_Core_Mailer
    
    function emailTemplate($templateFile, $args)
    {
    
        require_once 'Pman/Core/Mailer.php';
        $r = new Pman_Core_Mailer(array(
            'template'=>$templateFile,
            'contents' => $args,
            'page' => $this
        ));
        return $r->toData();
         
    }
    // DEPRICATED - USE Pman_Core_Mailer 
    // WHAT Part about DEPRICATED Does no one understand??
    function sendTemplate($templateFile, $args)
    {
        require_once 'Pman/Core/Mailer.php';
        $r = new Pman_Core_Mailer(array(
            'template'=>$templateFile,
            'contents' => array(),
            'page' => $this
        ));
        return $r->send();
        
    
    }
}
