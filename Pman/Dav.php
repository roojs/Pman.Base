<?php




require_once 'HTTP/WebDAV/Server.php';
// for some reason error reporting gets turned off!!!
/**
 *  Pos. windows fix..
 *  
 * http://support.microsoft.com/kb/907306
 * 
 * Dav - still under development...
 * 
 * -- This is dav/doc related - should be moved to dav folder...
 * 
 * 
 */
class Pman_Dav extends HTTP_WebDAV_Server
{
    function getAuth()
    {
        // this only allows access to owner company at present!!!
        $this->company = DB_DataObject::Factory('Companies');
        $this->company->isOwner = 1;
        $this->company->limit(1);
        $this->company->find(true);
        return true;
        
    }
    function start() {
       //DB_DataObject::debugLevel(1);
       ini_set('display_errors', true);
        // remove Dav.php!!!
       // $this->log("TEST", "TEST"); // breaks everything
        
        
        $this->_SERVER = $_SERVER;
    	$this->ServeRequest();
    } 
    function checkAuth($type, $user, $pass) 
    {
        if ($this->_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            return true;
        }
        //file_put_contents('/tmp/authtest', print_r($_SERVER,true));
        $u = DB_DataObject::factory('Person');
        $u->company_id = $this->company->id;
        //$this->log('AUTH', $user);
        
        if (!$u->get('email', $user)) {
            return false;
        }
        if (!$u->checkPassword($pass)) {
            return false;
        }
        $GLOBALS['Pman_Dav']['authUser'] = $u;
        $this->authUser = $u;
        return true;
        
        
    }

 
    // return : array($project , $doctype, $doc);
    
    function rootPath() {
        return  '/Dav/';
    }
    
     
    
    function GET(&$options) 
    {
        require_once 'Pman/Dav/Dir.php';
        $base = new Pman_Dav_Dir($this);
        $bits = explode('/', rtrim($options["path"],'/'));
        $dav = array_shift($bits);
        $dav = array_shift($bits); // really dav..
        //print_r($bits);
        
        $res = $base->resolve($bits);
        //var_dump($res);
        if ($res===false) {
            $this->http_status("404 Path expantion failed");
            echo "PATH EXPANSION FAILED";
            exit;
        }
        if ($res->mimetype != 'httpd/unix-directory') {
            // its a file...
            $options['mimetype'] = $res->mimetype;
            $fn = $res->document->getStoreName();
            $options['mtime'] = $res->contentlength;
            $options['size'] = $res->lastmodified;
            $options['stream'] = fopen($fn, "r");
                    
            return true;
        }
        
        
        
        $format = "%15s  %-19s  %-s\n";
        echo "<html><head><title>Index of ".htmlspecialchars($res->getPath())."</title></head>\n";
            
        echo "<h1>Index of ".htmlspecialchars($res->getPath())."</h1>\n";
            
        echo "<pre>";
        printf($format, "Size", "Last modified", "Filename");
        echo "<hr>";
        $ar = $res->toDavInfo(1);
        //print_r($ar);
        
        foreach($ar as $i=>$info) {
            
            if (!$i) {
                 // print_r($info);
                $info["path"] = dirname($info["path"]);
                $info["displayname"] = '..';
                if ($res->top) {
                    continue;
                }
            }
            printf($format, 
                      $info["getcontentlength"], 
                       strftime("%Y-%m-%d %H:%M:%S",$info["getlastmodified"]), 
                       "<a href=\"". $this->baseURL .  htmlspecialchars( $info["path"]) ."\">".
                          htmlspecialchars( $info["displayname"]) ."</a>");
            
        } 
        

        echo "</pre>";
 
        echo "</html>\n";
        exit;
    }
    
    
    function PROPFIND(&$options, &$files) 
    {
        require_once 'Pman/Dav/Dir.php';
        $base = new Pman_Dav_Dir($this);
        $bits = explode('/', rtrim($options["path"],'/'));
        $dav = array_shift($bits);
        $dav = array_shift($bits); // really dav..
        //print_r($bits);
        
        $res = $base->resolve($bits);
        //var_dump($res);
        if ($res===false) {
            $this->http_status("404 Path expantion failed");
            echo "PATH EXPANSION FAILED";
            $this->log('PROPFIND ', $options, '- PATH EXPANSION FAILED');
            exit;
        }
         
        if (empty($options["depth"])) {
            $info = $res->toDavInfo(0);
            $files["files"]= array($info);
            $this->log('PROPFIND ', $options, $files);
            return true;
        }
        $files["files"]= $res->toDavInfo(1);
        $this->log('PROPFIND ', $options, $files);
         
        return true;
    }
    
  
    
    var $putdata;
    var $putoptions;
    var $putfilename;
     
    function PUT(&$options) 
    {
        
        require_once 'Pman/Dav/Dir.php';
        $base = new Pman_Dav_Dir($this);
        $bits = explode('/', rtrim($options["path"],'/'));
        $dav = array_shift($bits);
        $dav = array_shift($bits); // really dav..
        $fn = array_pop($bits);
        
        $res = $base->resolve($bits);
        
         
        if ($res===false) {
            $this->log('PUT', $options , "404 Path expantion failed");
            $this->http_status("404 Path expantion failed");
            exit;
        }
        
        
        if (!$res->canUpload($fn)) {
              $this->log('PUT', $options , "404 Not allowed");
            $this->http_status("404 Not allowed");
            
            exit;
        }
        
        $doc = $res->resolve(array($fn));
        
        $options["new"] = !empty($doc);
        
        $tmpfile = tempnam('/tmp', 'dav-upload');
        
        $fp = fopen($tmpfile, "w");
        
        $this->putdir = $res;
        $this->putoptions = $options;
        $this->putfile = $tmpfile;
        $this->putname= $fn;
        $this->putmimetype= $options['content_type'];
        
        $this->log('PUT', $options , $tmpfile);
        return $fp;
    }
    function PUT_done()
    {
         $h= apache_request_headers();
        if (!file_exists($this->putfile) || !filesize($this->putfile)) {
            //unlink($this->putfile);
            return;
        }
        $this->putdir->authUser = $this->authUser;
        $this->log("PUTDONE", array(
            $this->putname, $this->putfile,$this->putmimetype
        ));
        $this->putdir->upload($this->putname, $this->putfile,$this->putmimetype);
        //unlink($this->putfile);
        //$this->putdata = $res;
        //$this->putoptions = $options;
        //$this->putfilename = $tmpfile;
    }
    
    
    function log($req, $opts, $ret=false) {
        $fh = fopen('/tmp/dav-log', 'a');
        fwrite($fh , date('Y-m-d').
                    " $req \n" . 
                    print_r(apache_request_headers(), true).
                    "\n" . 
           //       file_get_contents("php://input") .
                    "\n" . 
                    print_r($opts,true) . 
                    "\nRET : " . print_r($ret,true) ."\n\n");
        fclose($fh);
        
    }
    
}




