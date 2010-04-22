<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
* Simple_Auth - user authorization library for KohanaPHP framework
*
* @package			Simple_Auth
* @author				thejw23
* @copyright			(c) 2009 thejw23
* @license			http://www.opensource.org/licenses/isc-license.txt
* @version			1.4.1
* @last change			introduced class Simple_User to store user in session due to
* @last change			introduced class Auth_Modeler to separate projects
* @last change			small fix: cookies with invalid tokens are now deleted
* @last change			lower memory usage with it (with no DB queries on page): 
* 									before (Auth_User_Model in session): 2.00MB
* 									after (Simple_User in session): 1.30MB
* @last change			added force_login() 									
* @last change			get_user returns now a database result object (instead of an array)
* based on KohanaPHP Auth and Simple_Modeler
*/
class Simple_Auth_Core {

	// Session instance
	protected $session;

	// Configuration
	protected $config;
	
	/**
	 * Creates a new class instance, loading the session and storing config.
	 *
	 * @param array $config configuration
	 * @return void
	 */
	public function __construct($config = 'simple_auth')
	{
		// Load Session
		$this->session = Session::instance();

		// Save the config in the object
		$this->config = Kohana::config($config);
	}
	
	
	/**
	 * Create an instance of Simple_Auth.
	 *
	 * @param array $config configuration	 
	 * @return object
	 */
	public static function factory($config = 'simple_auth')
	{
		return new Simple_Auth($config);
	}

	/**
	 * Return a static instance of Simple_Auth.
	 *
	 * @param array $config configuration 
	 * @return object
	 */
	public static function instance($config = 'simple_auth')
	{
		static $instance;

		// Load the Auth instance
		empty($instance) and $instance = new Simple_Auth($config);

		return $instance;
	}
	
	/**
	 * Perform a hash, using the configured method.
	 *
	 * @param string $str password to hash
	 * @return string
	 */
	public function hash($str = '')
	{
		// on some servers hash() can be disabled :( then password are not encrypted 
		if (empty($this->config['hash_method']))
			return $this->config['salt_prefix'].$str.$this->config['salt_suffix']; 
		else
			return hash($this->config['hash_method'], $this->config['salt_prefix'].$str.$this->config['salt_suffix']); 
	} 
	
	
	/**
	 * Complete the login for a user by incrementing the logins and setting
	 * session data
	 *
	 * @param object $user user model object
	 * @return void
	 */
	protected function complete_login(Auth_Users_Model $user)
	{	

		// update time_stamp to login datetime
		$user->timestamp = array ('time_stamp');

		// Update the number of logins
		$user->logins += 1;
		
		// Set the last login time
		$user->last_time_stamp = $user->time_stamp;
		
		// set user ip
		$user->last_ip_address = $user->ip_address;
		$user->ip_address = $_SERVER['REMOTE_ADDR'];
		
		// Save the user
		$user->save();

		// Regenerate session_id
		$this->session->regenerate();

		// prepare user data to be stored in session
		$simple_user = new Simple_User;
		$simple_user->set_user($user->as_array());

		// Store user in session
		$this->session->set($this->config['session_key'], $simple_user);
		
		return TRUE;
	}

	/**
	 * Reload user properties from db 	 
	 *
	 * @return mixed
	 */
	public function reload_user() 
	{
		// only for logged in users
		if ($this->logged_in()) 
		{

			// get user id from session
			$user_data = $this->get_user();
			$user_model = new Auth_Users_Model($user_data->{$this->config['primary_key']});
			if ( ! $user_model->loaded())
			{
				// if there is no such user in database - quit
				return FALSE;
			}		 
			
			if (intval($user_model->active) === 1)
			{
				// if user is active, prepare user data to be stored in session
				$simple_user = new Simple_User;
				$simple_user->set_user($user_model->as_array());
				$this->session->set($this->config['session_key'], $simple_user);
			}
			else
			{
				// if user is inactive, log him out
				$this->logout();
			}
		}
	}

	/**
	 * Assign role to user, by default to current user
	 * 
	 * @param array $role array role=>status, where role is admin/active/moderator
	 *				and status is integer 0/1 or boolean	 
	 * @param object|integer $user user model object or user ID
	 * @return boolean	 	 
	 */
	public function set_role($role = array(), $user = 0) 
	{
		// role must be an array
		if ( ! is_array($role)) 
		{
			return FALSE;
		}

		// role must be an array with only valid roles (by default: admin, active, moderator)
		$role = array_intersect_key($role, $this->config['roles']);

		// if no valid key, quit 
		if (empty($role)) 
		{
			return FALSE;
		} 

		// if no user passed, get current user from session
		if (( ! is_object($user)) AND (intval($user) === 0))
		{
			$user = $this->get_user();
		}

		// if user is an object
		if (is_object($user) AND ($user instanceof Auth_Users_Model OR $user instanceof Simple_User)) 
		{
			// load user from database
			$user_model = new Auth_Users_Model($user->{$this->config['primary_key']});
			// for each $role assign new value 
			foreach ($role as $key => $value)
			{
				$user_model->$key = intval($value);
			}
			// and save
			return ($user_model->save()) ? TRUE : FALSE;
		}

		// if user ID was passed, try to load user from database
		$user_model = new Auth_Users_Model($user);

		if ( ! $user_model->loaded())
		{ 
			// if no such user, quit
			return FALSE;
		}

		// for each $role assign new value 
		foreach ($role as $key => $value)
		{
			$user_model->$key = intval($value);
		}

		// and save
		return ($user_model->save()) ? TRUE : FALSE;
	}

	/**
	 * Log a user out.
	 *
	 * @param boolean $destroy completely destroy the session
	 * @return boolean
	 */	
	public function logout($destroy = FALSE)
	{
		if ( ! $this->logged_in())
		{
			return FALSE;
		}

		$user = $this->get_user();
	
		if (intval($user->{$this->config['primary_key']}) !== 0) 
		{
			// delete user tokens
			Auth_Modeler::factory('auth_user_tokens')->delete_user_tokens($user->{$this->config['primary_key']});
		}

		if ($destroy === TRUE)
		{
			// Destroy the session completely
			Session::instance()->destroy();
		}
		else
		{
			// Remove the user from the session
			$this->session->delete($this->config['session_key']);

			// Regenerate session_id
			$this->session->regenerate();
		}
		
		// delete cookie. tokens from db will be deleted on next login.
		cookie::delete($this->config['cookie_key']);

		// Double check
		return ! $this->logged_in();
	}
	
	/**
	 * Checks if user has been already logged in
	 *
	 * @return boolean
	 */
	public function logged_in()
	{
		$status = FALSE;

		// Get the user from session
		$user = $this->session->get($this->config['session_key']);

		// if user is an object
		if (is_object($user) AND $user instanceof Simple_User)
		{
			// Everything is okay so far
			$status = TRUE;
		}

		// if no user in session check cookies/tokens for autologin
		if ( ! $status) $status = $this->auto_login();
			return $status;	
	}
	
	
	/**
	 * Gets the currently logged in user from the session.
	 * Returns FALSE if no user is currently logged in.
	 *
	 * @param object|integer $user unique user to be loaded	 
	 * @return mixed
	 */
	public function get_user($user = 0)
	{
		// if no user passed, get current user from session	
		if (( ! is_object($user)) AND (intval($user) === 0) AND ($this->logged_in())) 
		{
			return $this->session->get($this->config['session_key']);
		} 

		// if user object given 
		if (is_object($user) AND ($user instanceof Simple_User OR $user instanceof Auth_Users_Model)) 
		{
			// try to get user data from database
			$user_model = Auth_Modeler::Instance('auth_users')->fetch_row(intval($user->{$this->config['primary_key']}));
			if (!empty($user_model))  
			{
				return $user_model;
			} 
		}

		// if user ID was passed, try to fetch user data from database	 
		if (( ! is_object($user)) AND (intval($user) !== 0)) 
		{	
			$user_model = Auth_Modeler::Instance('auth_users')->fetch_row(intval($user));
			if (!empty($user_model)) 
			{
				return $user_model;
			}
		}

		return FALSE; 
	}
	
	/**
	 * Logs a user in, based on unique token stored in cookie.
	 *
	 * @return boolean
	 */
	public function auto_login()
	{
		// if token is stored in cookie
		if ($token = cookie::get($this->config['cookie_key']))
		{
			// Load the token and user
			$token_model = new Auth_User_Tokens_Model($token);
				
			// if token is not in the db
			if ( ! $token_model->loaded())
			{
				return FALSE;
			}

			// is token from the same browser?
			if ($token_model->user_agent === sha1(Kohana::$user_agent))
			{
				 // load user assigned to token
				 $user = new Auth_Users_Model($token_model->user_id);

				// if no user or inactive user, quit
				if ( ! $user->loaded() OR (intval($user->active) === 0)) 
				{
					// token is not needed any more
					$token_model->delete_user_tokens($token_model->user_id, TRUE);
					return FALSE;
				}

				// check if user has not expired 
				if (valid::date($user->active_to)) 
				{
					$now = date('Y-m-d H:i:s');
					if ($user->active_to < $now) 
					{
						return FALSE;
					}
				}

				// Save the token to create a new unique token
				$token_model->expires = date('Y-m-d H:i:s', mktime(date('H'), date('i'), date('s')+$this->config['lifetime'], date('m'), date('d'), date('Y'))); 
	
				$token_model->save();
				// Set the new cookie timers
				cookie::set($this->config['cookie_key'], $token_model->token, strtotime($token_model->expires) - time());

				// complete login
				$this->complete_login($user);

				// Automatic login was successful
				return TRUE;
			}

			// Token is invalid
			$token_model->delete();
		}

		return FALSE;
	}
	
	/**
	* Creates new user 
	*
	* @param array $user_data user data to add
	* @param string $second name of second unique field to verify
	* @return  boolean
	*/
	public function create_user($user_data = array(), $second = FALSE) 
	{
		// get password field name from config
		$password_field = $this->config['password'];

		// user_data must be an array
		if (empty($user_data) OR ! is_array($user_data)) 
		{
			// if not, quit
			return FALSE;
		}

		// create empty user object to save
		$user = new Auth_Users_Model();

		if ($second) 
		{
			// if second login field passed to verify with unique user login name 
			$user_exist = $user->user_exists($user_data[$this->config['unique']],$user_data[$this->config['unique_second']]);
		}
		else 
		{
			// verify unique user login name 
			$user_exist = $user->user_exists($user_data[$this->config['unique']]);
		}

		// check if username is unique
		if ( ! $user_exist)
		{
			// check if user account is time limited, if no valid time, remove from data to save
			if (isset($user_data['active_to']) AND ! valid::date($user_data['active_to']))
			{
				unset($user_data['active_to']);
			}
	
			// to make sure that $user_data['admin']=true works the same as $user_data['admin']=1 
			$roles = $this->config['roles'];
			foreach ($roles as $key=>$value) 
			{
				if (array_key_exists($key, $user_data))
				{
					$user_data[$key] = intval($user_data[$key]);
				}     
			}
	
			// assign user fields
			$user->set_fields($user_data);
			// hash the password
			$user->$password_field = $this->hash($user->$password_field);
	
			// add the user to db
			return ($result = $user->save()) ? $result : FALSE;
		} 

		return FALSE; 
	}


	/**
	* Deletes user from db 
	*
	* @param object|integer $user unique user id
	* @return boolean
	*/
	public function delete_user($user = 0) 
	{		
		// if user object given 
		if (is_object($user) AND ($user instanceof Simple_User OR $user instanceof Auth_Users_Model)) 
		{
			// try to get user data from database
			$user_model = new Auth_Users_Model(intval($user->{$this->config['primary_key']})); 
			if ($user_model->loaded()) 
			{
				return ($user_model->delete()) ? TRUE : FALSE;
			} 
		}

		// check if proper number given
		if (intval($user) === 0) 
		{
			return FALSE;
		} 

		// if user ID was passed, try to load user from database		    
		$user_model = new Auth_Users_Model(intval($user));
		if ( ! $user_model->loaded()) 
		{ 
			// no user in database, quit
			return FALSE;
		}
		
		// delete the user
		return ($user_model->delete()) ? TRUE : FALSE;  
	}

	/**
	 * Attempt to log in a user.
	 *
	 * @param string $user username to log in
	 * @param string $password password to check against
	 * @param boolean $remember enable auto-login
	 * @return boolean
	 */
	public function login($user = '', $password = '', $remember = FALSE)
	{
		// get password field name from config
		$password_field = $this->config['password'];

		// $user and $password must set, ane they must be string type 
		if (empty($password) OR ! is_string($password) OR ! is_string($user)) 
		{
			return FALSE;
		} 

		$user = new Auth_Users_Model(array('username' => $user,'password' => $password));
		
		// if there is no such user in database, quit
		if ( ! $user->loaded()) 
		{
			return FALSE;
		}

		if (is_string($password))
		{
			// Create a hashed password using the secrets from config
			$password = $this->hash($password);
		}

		// If user is active and the passwords match, perform a login
		if ((intval($user->active) === 1) AND ($user->$password_field == $password))
		{
			// check if user has not expired
			if (valid::date($user->active_to)) 
			{
				$now = date('Y-m-d H:i:s');
				if ($user->active_to<$now) 
				{
					return FALSE;
				}
			}

			if ($remember === TRUE)
			{	
				// Create a new autologin token
				$token_model = new Auth_User_Tokens_Model();

				// delete old user tokens
				$token_model->delete_user_tokens($user->{$this->config['primary_key']});
				
				// Set token data
				$token_model->user_id = $user->{$this->config['primary_key']};
				$token_model->expires =  date('Y-m-d H:i:s', mktime(date('H'), date('i'), date('s')+$this->config['lifetime'], date('m'), date('d'), date('Y')));
				
				// save token
				$token_model->save();

				// Set the autologin cookie                    
				cookie::set($this->config['cookie_key'], $token_model->token, $this->config['lifetime']);
			}

			// Finish the login
			$this->complete_login($user);

			return TRUE;
		}

		// Login failed
		return FALSE;
	}

	/**
	 * Log user by passing Simple_User object
	 *
	 * @param object $user Simple_User object
	 * @param boolean $remember enable auto-login
	 * @return boolean
	 */
	public function force_login(Simple_User $user, $remember = TRUE)
	{
		
		if (empty($user) OR ! $user instanceof Simple_User)
			return FALSE;
		
		$user = new Auth_Users_Model($user->id);
		
		// if there is no such user in database, quit
		if ( ! $user->loaded()) 
		{
			return FALSE;
		}

		// If user is active and the passwords match, perform a login
		if (intval($user->active) === 1)
		{
			// check if user has not expired
			if (valid::date($user->active_to)) 
			{
				$now = date('Y-m-d H:i:s');
				if ($user->active_to<$now) 
				{
					return FALSE;
				}
			}

			if ($remember === TRUE)
			{	
				// Create a new autologin token
				$token_model = new Auth_User_Tokens_Model();

				// delete old user tokens
				$token_model->delete_user_tokens($user->id);
				
				// Set token data
				$token_model->user_id = $user->id;
				$token_model->expires =  date('Y-m-d H:i:s', mktime(date('H'), date('i'), date('s')+$this->config['lifetime'], date('m'), date('d'), date('Y')));
				
				// save token
				$token_model->save();

				// Set the autologin cookie                    
				cookie::set($this->config['cookie_key'], $token_model->token, $this->config['lifetime']);
			}

			// Finish the login
			$this->complete_login($user);

			return TRUE;
		}

		// Login failed
		return FALSE;
	}

}
