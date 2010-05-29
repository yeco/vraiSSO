<?php

/**
*  vraiSSO
 * A simple single sign-on server using PHP session linking.
 * Don't use session.auto_start or session_start().
 */
class vraiSSO_SingleSignOn_Server
{
	/**
	 * Path to link files. Set this to use link files instead of symlinks.
	 * Don't forget to clean up old session files once in a while.
	 * 
	 * @var string
	 */
	public $links_path;

    /**
     * Flag to indicate the sessionStart has been called
     * @var boolean
     */
    protected $started=false;

    /**
     * Information of the brokers.
     * This should be data in a database.
     * 
     * @var array
     */
    protected static $brokers = array(
        'S1' => array('secret'=>"abc123"),
        'S2' => array('secret'=>"xyz789")
    );

    /**
     * Information of the users.
     * This should be data in a database.
     * 
     * @var array
     */
    protected static $users = array(
        'johnDoe' => array('password'=>"johnDoe1", 'fullname'=>"johnDoe Smit", 'email'=>"johnDoe@smit.com"),
        'janeDoe' => array('password'=>"janeDoe1", 'fullname'=>"janeDoe de Vries", 'email'=>"janeDoe@me.com")
    );
    
    /**
     * The current broker
     * @var string
     */
    protected $broker = null;
    
    
    /**
     * Class constructor.
     */
    public function __construct()
    {
    	if (!function_exists('symlink')) $this->links_path = sys_get_temp_dir();
    }
    
    /**
     * Start session and protect against session hijacking
     */
    protected function sessionStart()
    {
        if ($this->started) return;
        $this->started = true;
        
        // Broker session
        $matches = null;
        if (isset($_REQUEST[session_name()]) && preg_match('/^SSO-(\w*+)-(\w*+)-([a-z0-9]*+)$/', $_REQUEST[session_name()], $matches)) {
	        $sid = $_REQUEST[session_name()];
	        
	    	if (isset($this->links_path) && file_exists("{$this->links_path}/$sid")) {
	    		session_id(file_get_contents("{$this->links_path}/$sid"));
	    		session_start();
	    		setcookie(session_name(), "", 1);
	    	} else {
				session_start();
	    	}

            if (!isset($_SESSION['client_addr'])) {
                session_destroy();
                $this->fail("Not attached");
            }
            
            if ($this->generateSessionId($matches[1], $matches[2], $_SESSION['client_addr']) != $sid) {
                session_destroy();
                $this->fail("Invalid session id");
            }

            $this->broker = $matches[1];
            return;
        }

        // User session
        session_start();
        if (isset($_SESSION['client_addr']) && $_SESSION['client_addr'] != $_SERVER['REMOTE_ADDR']) session_regenerate_id(true);
        if (!isset($_SESSION['client_addr'])) $_SESSION['client_addr'] = $_SERVER['REMOTE_ADDR'];
    }
    
    /**
     * Generate session id from session token
     * 
     * @return string
     */
    protected function generateSessionId($broker, $token, $client_addr=null)
    {
        if (!isset(self::$brokers[$broker])) return null;

        if (!isset($client_addr)) $client_addr = $_SERVER['REMOTE_ADDR'];
        return "SSO-{$broker}-{$token}-" . md5('session' . $token . $client_addr . self::$brokers[$broker]['secret']);
    }
    
    /**
     * Generate session id from session token
     * 
     * @return string
     */
    protected function generateAttachChecksum($broker, $token)
    {
        if (!isset(self::$brokers[$broker])) return null;
        return md5('attach' . $token . $_SERVER['REMOTE_ADDR'] . self::$brokers[$broker]['secret']);
    }
    
    /**
     * Authenticate
     */
    public function login()
    {
        $this->sessionStart();

        if (empty($_POST['username'])) $this->failLogin("No user specified");
        if (empty($_POST['password'])) $this->failLogin("No password specified");
        
        if (!isset(self::$users[$_POST['username']]) || self::$users[$_POST['username']]['password'] != $_POST['password']) $this->failLogin("Incorrect credentials");
        
        $_SESSION['user'] = $_POST['username'];
        $this->info();
    }

    /**
     * Log out
     */
    public function logout()
    {
        $this->sessionStart();
        unset($_SESSION['user']);
        echo 1;
    }
    
    
    /**
     * Attach a user session to a broker session 
     */
    public function attach()
    {
        $this->sessionStart();
        
        if (empty($_REQUEST['broker'])) $this->fail("No broker specified");
        if (empty($_REQUEST['token'])) $this->fail("No token specified");
        if (empty($_REQUEST['checksum']) || $this->generateAttachChecksum($_REQUEST['broker'], $_REQUEST['token']) != $_REQUEST['checksum']) $this->fail("Invalid checksum");

        if (!isset($this->links_path)) {
	        $link = (session_save_path() ? session_save_path() : sys_get_temp_dir()) . "/sess_" . $this->generateSessionId($_REQUEST['broker'], $_REQUEST['token']);
	        if (!file_exists($link)) $attached = symlink('sess_' . session_id(), $link);
	        if (!$attached) trigger_error("Failed to attach; Symlink wasn't created.", E_USER_ERROR);
        } else {
	        $link = "{$this->links_path}/" . $this->generateSessionId($_REQUEST['broker'], $_REQUEST['token']);
	        if (!file_exists($link)) $attached = file_put_contents($link, session_id());
	        if (!$attached) trigger_error("Failed to attach; Link file wasn't created.", E_USER_ERROR);
		}

        if (isset($_REQUEST['redirect'])) {
            header("Location: " . $_REQUEST['redirect'], true, 307);
            exit;        
        }
        
        // Output an image specially for AJAX apps
        header("Content-Type: image/png");
        readfile("empty.png");
    }
    
    /**
     * Ouput user information as XML.
     * Doesn't return e-mail address to brokers with security level < 2.
     */
    public function info()
    {
        $this->sessionStart();
    	if (!isset($_SESSION['user'])) $this->failLogin("Not logged in");
    	
        header('Content-type: text/xml; charset=UTF-8');
    	echo '<?xml version="1.0" encoding="UTF-8" ?>', "\n";
    	
    	echo '<user identity="' . htmlspecialchars($_SESSION['user'], ENT_COMPAT, 'UTF-8') . '">';
    	echo '  <fullname>' . htmlspecialchars(self::$users[$_SESSION['user']]['fullname'], ENT_COMPAT, 'UTF-8') . '</fullname>';
		echo '  <email>' . htmlspecialchars(self::$users[$_SESSION['user']]['email'], ENT_COMPAT, 'UTF-8') . '</email>';
    	echo '</user>';
    }
    
    
    /**
     * An error occured.
     * I would normaly solve this by throwing an Exception and use an exception handler.
     *
     * @param string $message
     */
    protected function fail($message)
    {
        header("HTTP/1.1 406 Not Acceptable");
        echo $message;
        exit;
    }
    
    /**
     * Login failure.
     * I would normaly solve this by throwing a LoginException and use an exception handler.
     *
     * @param string $message
     */
    protected function failLogin($message)
    {
        header("HTTP/1.1 401 Unauthorized");
        echo $message;
        exit;
    }
}

// Execute controller command
if (realpath($_SERVER["SCRIPT_FILENAME"]) == realpath(__FILE__) && isset($_GET['cmd'])) {
    $ctl = new vraiSSO_SingleSignOn_Server();
    $ctl->$_GET['cmd']();
}
