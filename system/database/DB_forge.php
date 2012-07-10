<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * CodeIgniter
 *
 * An open source application development framework for PHP 5.2.4 or newer
 *
 * NOTICE OF LICENSE
 *
 * Licensed under the Open Software License version 3.0
 *
 * This source file is subject to the Open Software License (OSL 3.0) that is
 * bundled with this package in the files license.txt / license.rst.  It is
 * also available through the world wide web at this URL:
 * http://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to obtain it
 * through the world wide web, please send an email to
 * licensing@ellislab.com so we can send you a copy immediately.
 *
 * @package		CodeIgniter
 * @author		EllisLab Dev Team
 * @copyright	Copyright (c) 2008 - 2012, EllisLab, Inc. (http://ellislab.com/)
 * @license		http://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * @link		http://codeigniter.com
 * @since		Version 1.0
 * @filesource
 */

/**
 * Database Forge Class
 *
 * @category	Database
 * @author		EllisLab Dev Team
 * @link		http://codeigniter.com/user_guide/database/
 */
abstract class CI_DB_forge {

	public $fields		= array();
	public $keys		= array();
	public $primary_keys	= array();
	public $db_char_set	= '';

	// Platform specific SQL strings
	protected $_create_database	= 'CREATE DATABASE %s';
	protected $_drop_database	= 'DROP DATABASE %s';
	protected $_create_table_if	= 'CREATE TABLE IF NOT EXISTS';
	protected $_rename_table	= 'ALTER TABLE %s RENAME TO %s';
	protected $_drop_table_if	= 'DROP TABLE IF EXISTS';

	/**
	 * Constructor
	 *
	 * @return	void
	 */
	public function __construct()
	{
		// Assign the main database object to $this->db
		$CI =& get_instance();
		$this->db =& $CI->db;
		log_message('debug', 'Database Forge Class Initialized');
	}

	// --------------------------------------------------------------------

	/**
	 * Create database
	 *
	 * @param	string	the database name
	 * @return	bool
	 */
	public function create_database($db_name)
	{
		if ($this->_create_database === FALSE)
		{
			return ($this->db->db_debug) ? $this->db->display_error('db_unsuported_feature') : FALSE;
		}
		elseif ( ! $this->db->query(sprintf($this->_create_database, $db_name, $this->db->char_set, $this->db->dbcollat)))
		{
			return ($this->db->db_debug) ? $this->db->display_error('db_unable_to_drop') : FALSE;
		}

		if ( ! empty($this->db->data_cache['db_names']))
		{
			$this->db->data_cache['db_names'][] = $db_name;
		}

		return TRUE;
	}

	// --------------------------------------------------------------------

	/**
	 * Drop database
	 *
	 * @param	string	the database name
	 * @return	bool
	 */
	public function drop_database($db_name)
	{
		if ($db_name === '')
		{
			show_error('A table name is required for that operation.');
			return FALSE;
		}
		elseif ($this->_drop_database === FALSE)
		{
			return ($this->db->db_debug) ? $this->db->display_error('db_unsuported_feature') : FALSE;
		}
		elseif ( ! $this->db->query(sprintf($this->_drop_database, $db_name)))
		{
			return ($this->db->db_debug) ? $this->db->display_error('db_unable_to_drop') : FALSE;
		}

		if ( ! empty($this->db->data_cache['db_names']))
		{
			$key = array_search(strtolower($db_name), array_map('strtolower', $this->db->data_cache['db_names']), TRUE);
			if ($key !== FALSE)
			{
				unset($this->db->data_cache['db_names'][$key]);
			}
		}

		return TRUE;
	}

	// --------------------------------------------------------------------

	/**
	 * Add Key
	 *
	 * @param	string	key
	 * @param	bool	primary key
	 * @return	object
	 */
	public function add_key($key = '', $primary = FALSE)
	{
		if (empty($key))
		{
			show_error('Key information is required for that operation.');
		}

		if (is_array($key))
		{
			foreach ($key as $one)
			{
				$this->add_key($one, $primary);
			}

			return $this;
		}

		if ($primary === TRUE)
		{
			$this->primary_keys[] = $key;
		}
		else
		{
			$this->keys[] = $key;
		}

		return $this;
	}

	// --------------------------------------------------------------------

	/**
	 * Add Field
	 *
	 * @param	array
	 * @return	object
	 */
	public function add_field($field = '')
	{
		if (empty($field))
		{
			show_error('Field information is required.');
		}

		if (is_string($field))
		{
			if ($field === 'id')
			{
				$this->add_field(array(
					'id' => array(
						'type' => 'INT',
						'constraint' => 9,
						'auto_increment' => TRUE
					)
				));
				$this->add_key('id', TRUE);
			}
			else
			{
				if (strpos($field, ' ') === FALSE)
				{
					show_error('Field information is required for that operation.');
				}

				$this->fields[] = $field;
			}
		}

		if (is_array($field))
		{
			$this->fields = array_merge($this->fields, $field);
		}

		return $this;
	}

	// --------------------------------------------------------------------

	/**
	 * Create Table
	 *
	 * @param	string	the table name
	 * @param	bool	IF NOT EXISTS
	 * @return	bool
	 */
	public function create_table($table = '', $if_not_exists = FALSE)
	{
		if ($table === '')
		{
			show_error('A table name is required for that operation.');
		}
		else
		{
			$table = $this->db->dbprefix.$table;
		}

		if (count($this->fields) === 0)
		{
			show_error('Field information is required.');
		}

		$sql = $this->_create_table($table, $if_not_exists);

		if (is_bool($sql))
		{
			$this->_reset();
			if ($sql === FALSE)
			{
				return ($this->db->db_debug) ? $this->db->display_error('db_unsuported_feature') : FALSE;
			}
		}

		if (($result = $this->db->query($sql)) !== FALSE && ! empty($this->db->data_cache['table_names']))
		{
			$this->db->data_cache['table_names'][] = $table;
		}

		// Most databases don't support creating indexes from within the CREATE TABLE statement
		if ( ! empty($this->keys))
		{
			for ($i = 0, $sqls = $this->_process_indexes($table), $c = count($sqls); $i < $c; $i++)
			{
				$this->db->query($sqls[$i]);
			}
		}

		$this->_reset();
		return $result;
	}

	// --------------------------------------------------------------------

	/**
	 * Create Table
	 *
	 * @param	string	the table name
	 * @param	bool	should 'IF NOT EXISTS' be added to the SQL
	 * @return	mixed
	 */
	protected function _create_table($table, $if_not_exists)
	{
		$sql = 'CREATE TABLE';

		if ($if_not_exists === TRUE)
		{
			if ($this->_if_not_exists === FALSE)
			{
				if ($this->db->table_exists($table))
				{
					return TRUE;
				}
			}
			else
			{
				$sql = sprintf($this->_create_table_if, $this->db->escape_identifiers($table));
			}
		}

		return $sql.' '
			.$this->db->escape_identifiers($table).'('
			.$this->_process_fields()
			.$this->_process_primary_keys()
			."\n);";
	}

	// --------------------------------------------------------------------

	/**
	 * Drop Table
	 *
	 * @param	string	the table name
	 * @param	bool	IF EXISTS
	 * @return	bool
	 */
	public function drop_table($table_name, $if_exists = FALSE)
	{
		if ($table_name === '')
		{
			return ($this->db->db_debug) ? $this->db->display_error('db_table_name_required') : FALSE;
		}

		$query = $this->_drop_table($this->db->dbprefix.$table_name, $if_exists);
		if ($query === FALSE)
		{
			return ($this->db->db_debug) ? $this->db->display_error('db_unsuported_feature') : FALSE;
		}
		elseif ($query === TRUE)
		{
			return TRUE;
		}

		$query = $this->db->query($query);

		// Update table list cache
		if ($query && ! empty($this->db->data_cache['table_names']))
		{
			$key = array_search(strtolower($this->db->dbprefix.$table_name), array_map('strtolower', $this->db->data_cache['table_names']), TRUE);
			if ($key !== FALSE)
			{
				unset($this->db->data_cache['table_names'][$key]);
			}
		}

		return $query;
	}

	// --------------------------------------------------------------------

	/**
	 * Drop Table
	 *
	 * Generates a platform-specific DROP TABLE string
	 *
	 * @param	string	the table name
	 * @param	bool
	 * @return	string
	 */
	protected function _drop_table($table, $if_exists)
	{
		$sql = 'DROP TABLE';

		if ($if_exists)
		{
			if ($this->_drop_table_if === FALSE)
			{
				if ( ! $this->db->table_exists($table))
				{
					return TRUE;
				}
			}
			else
			{
				$sql = sprintf($this->_drop_table, $this->db->escape_identifiers($table));
			}
		}

		return $sql.' '.$this->db->escape_identifiers($table);
	}

	// --------------------------------------------------------------------

	/**
	 * Rename Table
	 *
	 * @param	string	the old table name
	 * @param	string	the new table name
	 * @return	bool
	 */
	public function rename_table($table_name, $new_table_name)
	{
		if ($table_name === '' OR $new_table_name === '')
		{
			show_error('A table name is required for that operation.');
			return FALSE;
		}
		elseif ($this->_rename_table === FALSE)
		{
			return ($this->db->db_debug) ? $this->db->display_error('db_unsuported_feature') : FALSE;
		}

		$result = $this->db->query(sprintf($this->_rename_table,
						$this->db->escape_identifiers($this->db->dbprefix.$table_name),
						$this->db->escape_identifiers($this->db->dbprefix.$new_table_name))
					);

		if ($result && ! empty($this->db->data_cache['table_names']))
		{
			$key = array_search(strtolower($this->db->dbprefix.$table_name), array_map('strtolower', $this->db->data_cache['table_names']), TRUE);
			if ($key !== FALSE)
			{
				$this->db->data_cache['table_names'][$key] = $this->db->dbprefix.$new_table_name;
			}
		}

		return $result;
	}

	// --------------------------------------------------------------------

	/**
	 * Column Add
	 *
	 * @param	string	the table name
	 * @param	string	the column name
	 * @param	string	the column definition
	 * @return	bool
	 */
	public function add_column($table = '', $field = array(), $after_field = '')
	{
		if ($table === '')
		{
			show_error('A table name is required for that operation.');
		}

		// add field info into field array, but we can only do one at a time
		// so we cycle through
		foreach (array_keys($field) as $k)
		{
			$this->add_field(array($k => $field[$k]));

			if (count($this->fields) === 0)
			{
				show_error('Field information is required.');
			}

			$sql = $this->_alter_table('ADD', $this->db->dbprefix.$table, $this->fields, $after_field);
			$this->_reset();

			if ($this->db->query($sql) === FALSE)
			{
				return FALSE;
			}
		}

		return TRUE;
	}

	// --------------------------------------------------------------------

	/**
	 * Column Drop
	 *
	 * @param	string	the table name
	 * @param	string	the column name
	 * @return	bool
	 */
	public function drop_column($table = '', $column_name = '')
	{
		if ($table === '')
		{
			show_error('A table name is required for that operation.');
		}

		if ($column_name === '')
		{
			show_error('A column name is required for that operation.');
		}

		return $this->db->query($this->_alter_table('DROP', $this->db->dbprefix.$table, $column_name));
	}

	// --------------------------------------------------------------------

	/**
	 * Column Modify
	 *
	 * @param	string	the table name
	 * @param	string	the column name
	 * @param	string	the column definition
	 * @return	bool
	 */
	public function modify_column($table = '', $field = array())
	{
		if ($table === '')
		{
			show_error('A table name is required for that operation.');
		}

		// add field info into field array, but we can only do one at a time
		// so we cycle through
		foreach (array_keys($field) as $k)
		{
			// If no name provided, use the current name
			if ( ! isset($field[$k]['name']))
			{
				$field[$k]['name'] = $k;
			}

			$this->add_field(array($k => $field[$k]));
			if (count($this->fields) === 0)
			{
				show_error('Field information is required.');
			}

			$sql = $this->_alter_table('CHANGE', $this->db->dbprefix.$table, $this->fields);
			$this->_reset();

			if ($this->db->query($sql) === FALSE)
			{
				return FALSE;
			}
		}

		return TRUE;
	}

	// --------------------------------------------------------------------

	/**
	 * Process fields
	 *
	 * @return	string
	 */
	protected function _process_fields()
	{
		foreach ($this->fields as $field => $attributes)
		{
			$attrs = array_change_key_case($attributes, CASE_UPPER);

			if (empty($attributes['TYPE']))
			{
				unset($this->fields[$field]);
				continue;
			}

			$this->fields[$field] = empty($attributes['NAME'])
						? "\n\t".$this->db->escape_identifiers($field)
						: "\n\t".$this->db->escape_identifiers($attributes['NAME']);

			$this->fields[$field] .= ' '.$attributes['TYPE'];

			if ( ! empty($attributes['CONSTRAINT']))
			{
				if (is_array($attributes['CONSTRAINT']))
				{
					$attributes['CONSTRAINT'] = implode(',', $attributes['CONSTRAINT']);
				}

				$this->fields[$field] .= '('.$attributes['CONSTRAINT'].')';
			}

			if (array_key_exists('DEFAULT', $attributes))
			{
				if ($attributes['DEFAULT'] === NULL)
				{
					// Override the NULL attribute if that's our default
					$attributes['NULL'] = TRUE;
					$attributes['DEFAULT'] = 'NULL';
				}
				else
				{
					$attributes['DEFAULT'] = $this->db->escape($attributes['DEFAULT']);
				}
			}

			$this->fields[$field] .= (empty($attributes['NULL']) && $attributes['NULL'] === TRUE)
						? ' NULL' : ' NOT NULL';

			if (isset($attributes['DEFAULT']))
			{
				$this->fields[$field] .= ' DEFAULT '.$attributes['DEFAULT'];
			}

			if ( ! empty($attributes['UNIQUE']) && $attributes['UNIQUE'] === TRUE)
			{
				$this->fields[$field] .= ' UNIQUE';
			}
		}

		if (empty($this->fields))
		{
			return FALSE;
		}

		return implode(',', $this->fields);
	}

	// --------------------------------------------------------------------

	/**
	 * Process primary keys
	 *
	 * @return	string
	 */
	protected function _process_primary_keys()
	{
		$sql = '';

		for ($i = 0, $c = count($this->primary_keys); $i < $c; $i++)
		{
			if ( ! isset($this->fields[$this->primary_keys[$i]]))
			{
				unset($this->primary_keys[$i]);
			}
		}

		if (count($primary_keys) > 0)
		{
			$sql .= ",\n\tCONSTRAINT ".$this->db->escape_identifiers('pk_'.implode('_', $this->primary_keys))
				.' PRIMARY KEY('.implode(', ', $this->db->escape_identifiers($this->primary_keys)).')';
		}

		return $sql;
	}

	// --------------------------------------------------------------------

	/**
	 * Process indexes
	 *
	 * @param	string
	 * @return	string
	 */
	protected function _process_indexes($table = NULL)
	{
		$table = $this->db->escape_identifiers($table);
		$sqls = array();

		for ($i = 0, $c = count($this->keys); $i < $c; $i++)
		{
			if ( ! isset($this->fields[$this->keys[$i]]))
			{
				unset($this->keys[$i]);
				continue;
			}

			is_array($this->keys[$i]) OR $this->keys[$i] = array($this->keys[$i]);

			$sqls[] = 'CREATE INDEX '.$this->db->escape_identifiers(implode('_', $this->keys[$i]))
				.' ON '.$this->db->escape_identifiers($table)
				.' ('.implode(', ', $this->db->escape_identifiers($this->keys[$i])).');';
		}

		return $sqls;
	}

	// --------------------------------------------------------------------

	/**
	 * Reset
	 *
	 * Resets table creation vars
	 *
	 * @return	void
	 */
	protected function _reset()
	{
		$this->fields = $this->keys = $this->primary_keys = array();
	}

}

/* End of file DB_forge.php */
/* Location: ./system/database/DB_forge.php */