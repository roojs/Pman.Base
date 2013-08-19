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
         // move away from doing this ... you can access bootLoader.XXXXXX in the master template..
        $boot = HTML_FlexyFramework::get();
        // echo'<PRE>';print_R($boot);exit;
        $this->appName= $boot->appName;
        $this->appNameShort= $boot->appNameShort;
        
        
        $this->appModules= $boot->enable;
        $this->isDev = empty($boot->Pman['isDev']) ? false : $boot->Pman['isDev'];
        $this->appDisable = $boot->disable;
        $this->appDisabled = explode(',', $boot->disable);
        $this->version = $boot->version; 
        $this->uiConfig = empty($boot->Pman['uiConfig']) ? false : $boot->Pman['uiConfig']; 
        
        if (!empty($ff->Pman['local_autoauth']) && 
            ($_SERVER['SERVER_ADDR'] == '127.0.0.1') &&
            ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') 
        ) {
            $this->isDev = true;
        }
        //var_dump($this->appModules);
        foreach(explode(',',$this->appModules) as $m) {
            $cls = 'Pman_'. $m . '_Pman';
            //echo $cls;
            //echo $this->rootDir . '/'.str_replace('_','/', $cls). '.php';
            
            if (!file_exists($this->rootDir . '/'.str_replace('_','/', $cls). '.php')) {
                continue;
            }
            require_once str_replace('_','/', $cls). '.php';
            $c = new $cls();
            if (method_exists($c,'init')) {
                $c->init($this);
            }
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
        
        
        
        if ($this->company->logo_id) {
            $im = DB_DataObject::Factory('Images');
            $im->get($this->company->logo_id);
            $this->appLogo = $this->baseURL . '/Images/Thumb/300x100/'. $this->company->logo_id .'/' . $im->filename;
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
     *
     * @return {Pman_Core_DataObjects_Companies} the owner company
     */
    function loadOwnerCompany()
    {
         
        $this->company = DB_DataObject::Factory('Companies');
        if (!is_a($this->company, 'DB_DataObject')) { // non-core pman projects
            return false; 
        }
        $this->company->get('comptype', 'OWNER');
        return $this->company;
    }
    
    
    
    /**
     * getAuthUser: - get the authenticated user..
     *
     * @return {DB_DataObject} of type Pman[authTable] if authenticated.
     */
    
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
    /**
     * hasPerm:
     * wrapper arround authuser->hasPerm
     * @see Pman_Core_DataObjects_User::hasPerm
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
        $this->init();
        
        $mods = explode(',', $this->appModules);
        if (in_array('Core',$mods)) { // core has to be the first  modules loaded as it contains Pman.js
            array_unshift($mods,   'Core');
        }
        
        $mods = array_unique($mods);
         
        $disabled =  explode(',', $this->appDisable ? $this->appDisable: '');
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
    
    function jerr($str, $errors=array(), $content_type = false) // standard error reporting..
    {
        $this->addEvent("ERROR", false, $str);
         
        $cli = HTML_FlexyFramework::get()->cli;
        if ($cli) {
            echo "ERROR: " .$str . "\n";
            exit;
        }
        
        
        if ($content_type == 'text/plain') {
            header('Content-Disposition: attachment; filename="error.txt"');
            header('Content-type: '. $content_type);
            echo "ERROR: " .$str . "\n";
            exit;
        } 
        
        
        
        require_once 'Services/JSON.php';
        $json = new Services_JSON();
        
        // log all errors!!!
        
        
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
        $cli = HTML_FlexyFramework::get()->cli;
        if ($cli) {
            echo "OK: " .$str . "\n";
            exit;
        }
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
    function jdata($ar,$total=false, $extra=array(), $cachekey = false)
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
        
      
        $ret =  $json->encode(array('success' =>  true, 'total'=> $total, 'data' => $ar) + $extra);  
        
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
        // and finally the JsTemplate...
        echo '<script type="text/javascript" src="'. $this->baseURL. '/Core/JsTemplate"></script>'."\n";
         
    }
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
        
        
        foreach($mods as $mod) {
            // add the css file..
            $dir = $this->rootDir.'/Pman/'.$mod;
            $ar = glob($dir . '/*.css');
            foreach($ar as $fn) { 
                $css = $this->rootURL .'/Pman/'.$mod.'/'.basename($fn);
                echo '<link rel="stylesheet" type="text/css" href="'.$css.'" />'."\n";
            }
             
            
        }
         
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
        
        $compile  = empty($ff->Pman['public_cache_dir']) ? 0 : 1;
        $basedir = $compile ? $ff->Pman['public_cache_dir'] : false;
        $baseurl = $compile ? $ff->Pman['public_cache_url'] : false;
        
        $lsort = create_function('$a,$b','return strlen($a) > strlen($b) ? 1 : -1;');
        usort($files, $lsort);
        
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
    
    static $permitError = false;
    
    function onPearError($err)
    {
        static $reported = false;
        if ($reported) {
            return;
        }
        
        if (Pman::$permitError) {
             
            return;
            
        }
        
        
        $reported = true;
        $out = $err->toString();
        
        
        //print_R($bt); exit;
        $ret = array();
        $n = 0;
        foreach($err->backtrace as $b) {
            $ret[] = @$b['file'] . '(' . @$b['line'] . ')@' .   @$b['class'] . '::' . @$b['function'];
            if ($n > 20) {
                break;
            }
            $n++;
        }
        //convert the huge backtrace into something that is readable..
        $out .= "\n" . implode("\n",  $ret);
     
        
        $this->jerr($out);
        
        
        
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
        $au = $this->getAuthUser();
       
        $e = DB_DataObject::factory('Events');
        $e->init($act,$obj,$remarks); 
         
        $e->event_when = date('Y-m-d H:i:s');
        
        $eid = $e->insert();
        
        // fixme - this should be in onInsert..
        $wa = DB_DataObject::factory('core_watch');
        if (method_exists($wa,'notifyEvent')) {
            $wa->notifyEvent($e); // trigger any actions..
        }
        
        
        $e->onInsert($_REQUEST, $this);
        
       
        return $e;
        
    }
    // ------------------ DEPERCIATED ----------------------------
     
    // DEPRECITAED - use moduleslist
    function modules()  { return $this->modulesList();  }
    
    // DEPRECIATED.. - use getAuthUser...
    function staticGetAuthUser()  { $x = new Pman(); return $x->getAuthUser();  }
     
    
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
            'contents' => $args,
            'page' => $this
        ));
        return $r->send();
        
    
    }
}
