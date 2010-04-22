<?php
/**
* Auth_Modeler  
*
* @package		Simple_Auth
* @author			thejw23
* @copyright		(c) 2009 thejw23
* @license		http://www.opensource.org/licenses/isc-license.txt
* @version		1.0
* 
* @NOTICE			table columns should be different from class varibales/methods names
* @NOTICE			ie. having table column 'timestamp' or 'skip' may (and probably will) lead to problems
*  
* modified version of Simple_Modeler, removed all methods not needed for Auth  
* class name changed to prevent conflicts while using original Simple_Modeler 
*/
class Auth_Modeler_Core extends Model {

	// database table name
	protected $table_name = '';
	
	// primary key for the table
	protected $primary_key = 'id';
	 		
	// if true all fields will be trimmed before save
	protected $auto_trim = FALSE;
	
	// store single record database fields and values
	protected $data = Array();
	protected $data_original = Array();
		
	// array, 'form field name' => 'database field name'
	public $aliases = Array(); 

	// skip those fields from save to database
	public $skip = Array ();

	// timestamp fields, they will be auto updated on db update
	// update is only if table has a column with given name
	public $timestamp = Array('time_stamp');
	
	// timestamp fields updated only on db insert
	public $timestamp_created = Array('time_stamp_created');

	// type of where statement: where, orwhere, like, orlike...
	public $where = 'where';
	
	// fetch only those fields, if empty select all fields
	public $select;
	
	//db result object type
	public $result_object = 'stdClass'; //defaults, arrays: MYSQL_ASSOC objects: stdClass
	
	/**
	* Constructor
	*
	* @param integer|array $id unique record to be loaded	
	* @return void
	*/
	public function __construct($id = NULL)
	{
		parent::__construct();

		if ($id != NULL)
		{
			$this->load($id);
		}
	}
	
	/**
	* Return a static instance of Simple_Modeler.
	* Useful for one line method chaining.	
	*
	* @param string $model name of the model class to be created
	* @param integer|array $id unique record to be loaded	
	* @return object
	*/
	public static function factory($model = FALSE, $id = FALSE)
	{
		$model = empty($model) ? __CLASS__ : ucwords($model).'_Model';
		return new $model($id);
	}
	
	/**
	* Create an instance of Simple_Modeler.
	* Useful for one line method chaining.	
	*
	* @param string $model name of the model class to be created
	* @param integer|array $id unique record to be loaded	
	* @return object
	*/
	public static function instance($model = FALSE, $id = FALSE)
	{
		static $instance;
		$model = empty($model) ? __CLASS__ : ucwords($model).'_Model';
		// Load the Simple_Modeler instance
		empty($instance) and $instance = new $model($id);

		return $instance;
	}

	/**
	* Shows table name of the loaded model
	*	
	* @return string
	*/
	public function get_table_name() 
	{
			return $this->table_name;
	}
	
	/**
	*  Allows for setting data fields in bulk	
	*
	* @param array $data data passed to $data
	* @return object
	*/
	public function set_fields($data)
	{
		// make sure that table columns are loaded
		$this->load_columns();

		// assign new valuse to current data
		foreach ($data as $key => $value)
		{
			$key = $this->check_alias($key);

			if (array_key_exists($key, $this->data))
			{
				// skip field not existing in current table
				($this->auto_trim) ? $this->data[$key] = trim($value) : $this->data[$key] = $value;
			}
		}
		
		return $this;
	}

	/**
	*  Saves the current $data to DB	
	*
	* @return mixed
	*/
	public function save()
	{
		// make sure that table columns are loaded
		$this->load_columns();

		// $data_to_save=$this->data;
		$data_to_save = array_diff_assoc($this->data, $this->data_original);

		// if no changes, quit
		if (empty($data_to_save))
		{
			return NULL;
		}

		if ($this->loaded())
		{
			$data_to_save = $this->check_timestamp($data_to_save, FALSE);
		}
		else
		{
			$data_to_save = $this->check_timestamp($data_to_save, TRUE);
		}

		$data_to_save = $this->check_skip($data_to_save);

		// Do an update
		if ($this->loaded())
		{ 
			return count($this->db->update($this->table_name, $data_to_save, array($this->primary_key => $this->data[$this->primary_key])));
		}
		else // Do an insert
		{
			$id = $this->db->insert($this->table_name, $data_to_save)->insert_id();
			return ($this->data[$this->primary_key] = $id);
		}
		
		return NULL;
	}
	
	/**
	* load single record based on unique field value	
	*
	* @param array|integer $value column value
	* @param string $key column name  	 
	* @return object
	*/
	public function load($value, $key = NULL)
	{
		(empty($key)) ? $key = $this->primary_key : NULL;

		$type = $this->where;
		// make sure that table columns are loaded
		$this->load_columns();
			
			//  get data , inflector::singular(ucwords($this->table_name)).'_Model'
			// if value is an array, make where statement and load data
			if (is_array($value))
			{
				$data = $this->db->select($this->select)->$type($value)->get($this->table_name)->result(TRUE);
			}
			else // else load by default ID key
			{
				$data = $this->db->select($this->select)->$type(array($key => $value))->get($this->table_name)->result(TRUE);
			}

			// try and assign the data
			if (count($data) === 1 AND $data = $data->current())
			{
				// set original data
				$this->data_original = (array) $data;
				// set current data
				$this->data = $this->data_original; 
			}

			return $this;

	}
	
	/**
	*  Returns single record without using $data		
	*
	* @param array|integer $value column value
	* @param string $key column name  	
	* @return mixed
	*/
	public function fetch_row($value, $key = NULL) 
	{
		(empty($key)) ? $key = $this->primary_key : NULL;

		$type = $this->where;
			
			// get data
			// if value is an array, make where statement and load data
			if (is_array($value))
			{
				$data = $this->db->select($this->select)->$type($value)->get($this->table_name)->result(TRUE, $this->result_object);
			}
			else // else load by default ID key
			{
				$data = $this->db->select($this->select)->$type(array($key => $value))->get($this->table_name)->result(TRUE, $this->result_object);
			}

			// try and assign the data
			if (count($data) === 1 AND $data = $data->current())
			{			
				return $data;
			}

	}
	
	
	/**
	* Deletes from db current record or condition based records 	
	*
	* @param array $what data to be deleted
	* @return mixed
	*/  
	public function delete($what = array())
	{
		// delete by conditions
		if (( ! empty($what)) AND (is_array($what)))
		{
			// delete  based on passed conditions
			return $this->db->delete($this->table_name, $what);
		}
		// else delete current record
		elseif (intval($this->data[$this->primary_key]) !== 0) 
		{
			// if no conditions and data is loaded -  delete current loaded data by ID
			return $this->db->delete($this->table_name, array($this->primary_key => $this->data[$this->primary_key]));
		}
	}
	
	
	/**
	*  clear values of $data and $data_original
	*
	* @return 
	*/
	public function clear_data()
	{
		array_fill_keys($this->data, '');
		array_fill_keys($this->data_original, '');
	}
	
	/**
	*  Set where statement	
	*
	* @param string $where query where
	* @return object
	*/
	public function where($where = NULL)
	{
		if ( ! empty($where))
		{
			$this->where = $where;
		}

		return $this;
	}
	
	/**
	*  Set columns for select
	*
	* @param array $fields query select
	* @return object
	*/
	public function select($fields = array())
	{
		if (empty($fields)) 
			return $this;

		if (is_array($fields))
		{
			$this->select = $fields;
		}
		elseif(func_num_args() > 0)
		{
			$this->select = func_get_args();
		}

		return $this;
	} 

	/**
	*  Checks if given key is an alias and if so then points to aliased field name	
	*
	* @param string $key key to be checked
	* @return boolean
	*/
	public function check_alias($key)
	{
		return array_key_exists($key, $this->aliases) === TRUE ? $this->aliases[$key] : $key;
	}

	/**
	*  check if data has been retrived from db and has a primary key value other than 0	
	*
	* @param string $field data key to be checked
	* @return boolean
	*/	
	public function loaded($field = NULL) 
	{ 
		(empty($field)) ? $field = $this->primary_key : NULL;
		return (intval($this->data[$field]) !== 0) ? TRUE : FALSE;
	}

	/**
	*  load table fields into $data	
	*
	* @return void
	*/
	public function load_columns() 
	{
		if ( ! empty($this->data) AND (empty($this->data_original)) )
			foreach ($this->data as $key => $value) 
			{
				$this->data_original[$key] = '';
			}
	}
	
	/**
	*  get table columns from db	
	*
	* @return array
	*/  
	public function explain()
	{
		// get columns from database
		$columns = array_keys($this->db->list_fields($this->table_name, TRUE));
		$data = array();

		// assign default empty values
		foreach ($columns as $column) 
		{ 
			$data[$column] = '';
		}
		return $data;
	}
	
	/**
	*  return current loaded data	
	*
	* @return array
	*/ 
	public function as_array()
	{
		return $this->data;
	}
	
	/**
	*  Set the DB results object type	
	*
	* @param string $object type or returned object
	* @return object
	*/
	public function set_result($object = stdClass) 
	{
		$this->result_object = $object;
		return $this; 
	}
	
	/**
	*  Checks if given key is a timestamp and should be updated	
	*
	* @param string $key key to be checked
	* @return array
	*/
	 public function check_timestamp($data, $create = FALSE)
	 {
		// update timestamp fields with current datetime
		if ( ! $create)
		{
			if ( ! empty($this->timestamp) AND is_array($this->timestamp))
				foreach ($this->timestamp as $field)
					if (array_key_exists($field, $this->data_original))
					{
						$data[$field] = date('Y-m-d H:i:s');
					}
		}
		else
		{
			if ( ! empty($this->timestamp_created) AND is_array($this->timestamp_created))
				foreach ($this->timestamp_created as $field)
					if (array_key_exists($field, $this->data_original))
					{
						$data[$field] = date('Y-m-d H:i:s');
					}
		}
		
		return $data;
	 }
	 
	/**
	*  Checks if given key should be skipped	
	*
	* @param array $data data to be checked
	* @return object
	*/
	 public function check_skip($data)
	 {
		if ( ! empty($this->skip) AND is_array($this->skip))
			foreach ($this->skip as $skip)
				if (array_key_exists($skip, $data))
				{ 
					unset($data[$skip]);
				}
				
		return $data;
	 }


	/**
	*  Magic get from $data	
	*
	* @param string $key key to be retrived
	* @return mixed
	*/
	public function __get($key)
	{
		$key = $this->check_alias($key);

		if (array_key_exists($key, $this->data))
		{
			return $this->data[$key];
		}
		return NULL;
	}

	/**
	*  magic set to $data	
	*
	* @param string $key key to be modified
	* @param string $value value to be set
	* @return object
	*/
	public function __set($key, $value)
	{
		$key = $this->check_alias($key);

		if (array_key_exists($key, $this->data) AND (empty($this->data[$key]) OR $this->data[$key] !== $value))
		{
			return ($this->auto_trim) ? $this->data[$key] = trim($value) : $this->data[$key] = $value;
		}
		return NULL;
	}

	/**
	*  serialize only needed values (without DB connection)	
	*
	* @return array
	*/
	public function __sleep()
	{
		// Store only information about the object
		return array('skip','aliases','timestamp','timestamp_created','table_name','data_original','data','primary_key','where','select','auto_trim','result_object');
	}

	/**
	*  unserialize	
	*
	* @return void
	*/
	public function __wakeup()
	{
		// Initialize database
		parent::__construct();
	}

}