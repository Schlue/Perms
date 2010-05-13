<?php
/**
 * The Horde_Perms_Sql:: class provides a SQL driver for the Horde
 * permissions system.
 *
 * Copyright 2008-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author   Duck <duck@obala.net>
 * @category Horde
 * @package  Horde_Perms
 */
class Horde_Perms_Sql extends Horde_Perms
{
    /**
     * Configuration parameters.
     *
     * @var array
     */
    protected $_params = array();

    /**
     * Handle for the current database connection.
     *
     * @var DB
     */
    protected $_db;

    /**
     * Handle for the current database connection, used for writing. Defaults
     * to the same handle as $db if a separate write database is not required.
     *
     * @var DB
     */
    protected $_write_db;

    /**
     * Incrementing version number if cached classes change.
     *
     * @var integer
     */
    private $_cacheVersion = 2;

    /**
     * Cache of previously retrieved permissions.
     *
     * @var array
     */
    protected $_permsCache = array();

    /**
     * Constructor.
     *
     * @param array $params  Configuration parameters (in addition to base
     *                       Horde_Perms parameters):
     * <pre>
     * 'db' - (DB) [REQUIRED] The DB instance.
     * 'table' - (string) The name of the perms table in 'database'.
     *           DEFAULT: 'horde_perms'
     * 'write_db' - (DB) The write DB instance.
     * </pre>
     *
     * @throws Horde_Perms_Exception
     */
    public function __construct($params = array())
    {
        if (!isset($params['db'])) {
            throw new Horde_Perms_Exception('Missing db parameter.');
        }
        $this->_db = $params['db'];

        if (isset($params['write_db'])) {
            $this->_write_db = $params['write_db'];
        }

        unset($params['db'], $params['write_db']);

        $this->_params = array_merge(array(
            'table' => 'horde_perms'
        ), $this->_params, $params);

        parent::__construct($params);
    }

    /**
     * Returns a new permissions object.
     *
     * @param string $name  The permission's name.
     *
     * @return Horde_Perms_Permission_SqlObject  A new permissions object.
     */
    public function newPermission($name)
    {
        $type = 'matrix';
        $params = null;

        if ($pos = strpos($name, ':')) {
            try {
                $info = $this->getApplicationPermissions(substr($name, 0, $pos));
                if (isset($info['type']) && isset($info['type'][$name])) {
                    $type = $info['type'][$name];
                }

                if (isset($info['params']) && isset($info['params'][$name])) {
                    $params = $info['params'][$name];
                }
            } catch (Horde_Perms_Exception $e) {}
        }

        return new Horde_Perms_Permission_SqlObject($name, $this->_cacheVersion, $type, $params);
    }

    /**
     * Returns an object corresponding to the named permission, with the
     * users and other data retrieved appropriately.
     *
     * @param string $name  The name of the permission to retrieve.
     *
     * @return Horde_Perms_Permission_SqlObject  TODO
     * @throw Horde_Perms_Exception
     */
    public function getPermission($name)
    {
        if (isset($this->_permsCache[$name])) {
            return $this->_permsCache[$name];
        }

        $perm = $this->_cache->get('perm_sql' . $this->_cacheVersion . $name, $GLOBALS['conf']['cache']['default_lifetime']);
        if (empty($perm)) {
            $query = 'SELECT perm_id, perm_data FROM ' .
                $this->_params['table'] . ' WHERE perm_name = ?';
            $result = $this->_db->getRow($query, array($name), DB_FETCHMODE_ASSOC);

            if ($result instanceof PEAR_Error) {
                throw new Horde_Perms_Exception($result);
            } elseif (empty($result)) {
                throw new Horde_Perms_Exception('Does not exist');
            }

            $object = new Horde_Perms_Permission_SqlObject($name, $this->_cacheVersion);
            $object->setId($result['perm_id']);
            $object->setData(unserialize($result['perm_data']));

            $this->_cache->set('perm_sql' . $this->_cacheVersion . $name, serialize($object));

            $this->_permsCache[$name] = $object;
        } else {
            $this->_permsCache[$name] = unserialize($perm);
        }

        $this->_permsCache[$name]->setSQLOb($this->_write_db);

        return $this->_permsCache[$name];
    }

    /**
     * Returns a permission object corresponding to the given unique ID,
     * with the users and other data retrieved appropriately.
     *
     * @param integer $id  The unique ID of the permission to retrieve.
     *
     * @return Horde_Perms_Permission_SqlObject  TODO
     * @throws Horde_Perms_Exception
     */
    public function getPermissionById($id)
    {
        if ($id == Horde_Perms::ROOT || empty($id)) {
            $object = $this->newPermission(Horde_Perms::ROOT);
        } else {
            $query = 'SELECT perm_name, perm_data FROM ' .
                $this->_params['table'] . ' WHERE perm_id = ?';
            $result = $this->_db->getRow($query, array($id), DB_FETCHMODE_ASSOC);

            if ($result instanceof PEAR_Error) {
                throw new Horde_Perms_Exception($result);
            } elseif (empty($result)) {
                throw new Horde_Perms_Exception('Does not exist');
            }

            $object = new Horde_Perms_Permission_SqlObject($result['perm_name'], $this->_cacheVersion);
            $object->setId($id);
            $object->setData(unserialize($result['perm_data']));
            $object->setSQLOb($this->_write_db);
        }

        return $object;
    }

    /**
     * Adds a permission to the permissions system. The permission must first
     * be created with newPermission(), and have any initial users added to
     * it, before this function is called.
     *
     * @param Horde_Perms_Permission_SqlObject $perm  The perm object.
     *
     * @return TODO
     * @throws Horde_Perms_Exception
     */
    public function addPermission(Horde_Perms_Permission_SqlObject $perm)
    {
        $name = $perm->getName();
        if (empty($name)) {
            throw new Horde_Perms_Exception('Permission name must be non-empty.');
        }

        $this->_cache->expire('perm_sql' . $this->_cacheVersion . $name);
        $this->_cache->expire('perm_sql_exists_' . $this->_cacheVersion . $name);

        $id = $this->_write_db->nextId($this->_params['table']);

        // remove root from the name
        $root = Horde_Perms::ROOT . ':';
        if (substr($name, 0, strlen($root)) == ($root)) {
            $name = substr($name, strlen($root));
        }

        // build parents
        $parents = '';
        if (($pos = strrpos($name, ':')) !== false) {
            $parent_name = substr($name, 0, $pos);
            $query = 'SELECT perm_id, perm_parents FROM ' .
                $this->_params['table'] . ' WHERE perm_name = ?';
            $result = $this->_db->getRow($query, array($parent_name), DB_FETCHMODE_ASSOC);
            if (!empty($result)) {
                $parents = $result['perm_parents'] . ':' . $result['perm_id'];
            }
        }

        $query = 'INSERT INTO ' . $this->_params['table'] .
            ' (perm_id, perm_name, perm_parents) VALUES (?, ?, ?)';
        $perm->setId($id);

        $result = $this->_write_db->query($query, array($id, $name, $parents));
        if ($result instanceof PEAR_Error) {
            throw new Horde_Perms_Exception($result);
        }

        $perm->setSQLOb($this->_write_db);
        $perm->save();

        return $id;
    }

    /**
     * Removes a permission from the permissions system permanently.
     *
     * @param Horde_Perms_Permission_SqlObject $perm  The permission to
     *                                                remove.
     * @param boolean $force                          Force to remove ever
     *                                                child.
     *
     * @return TODO
     * @throws Horde_Perms_Exception
     */
    public function removePermission(Horde_Perms_Permission_SqlObject $perm, $force = false)
    {
        $name = $perm->getName();
        $this->_cache->expire('perm_sql' . $this->_cacheVersion . $name);
        $this->_cache->expire('perm_sql_exists_' . $this->_cacheVersion . $name);

        $query = 'DELETE FROM ' . $this->_params['table'] .
            ' WHERE perm_name = ?';
        $result = $this->_write_db->query($query, array($name));
        if ($result instanceof PEAR_Error) {
            throw new Horde_Perms_Exception($result);
        } elseif ($force) {
            return $result;
        }

        $query = 'DELETE FROM ' . $this->_params['table'] .
            ' WHERE perm_name LIKE ?';
        return $this->_write_db->query($query, array($name . ':%'));
    }

    /**
     * Returns the unique identifier of this permission.
     *
     * @param Horde_Perms_Permission_SqlObject $perm  The permission object to
     *                                                 get the ID of.
     *
     * @return integer  The unique id.
     */
    public function getPermissionId($permission)
    {
        if ($permission->getName() == Horde_Perms::ROOT) {
            return Horde_Perms::ROOT;
        }

        $query = 'SELECT perm_id FROM ' . $this->_params['table'] .
            ' WHERE perm_name = ?';
        return $this->_db->getOne($query, array($permission->getName()));
    }

    /**
     * Checks if a permission exists in the system.
     *
     * @param string $permission  The permission to check.
     *
     * @return boolean  True if the permission exists.
     * @throws Horde_Perms_Exception
     */
    public function exists($permission)
    {
        $key = 'perm_sql_exists_' . $this->_cacheVersion . $permission;
        $exists = $this->_cache->get($key, $GLOBALS['conf']['cache']['default_lifetime']);
        if ($exists === false) {
            $query = 'SELECT COUNT(*) FROM ' . $this->_params['table'] .
                ' WHERE perm_name = ?';
            $exists = $this->_db->getOne($query, array($permission));
            if ($exists instanceof PEAR_Error) {
                throw new Horde_Perms_Exception($exists);
            }

            $this->_cache->set($key, (string)$exists);
        }

        return (bool)$exists;
    }

    /**
     * Returns a child's direct parent ID.
     *
     * @param mixed $child  The object name for which to look up the parent's
     *                      ID.
     *
     * @return integer  The unique ID of the parent.
     * @throws Horde_Perms_Exception
     */
    public function getParent($child)
    {
        $query = 'SELECT perm_parents FROM ' . $this->_params['table'] .
            ' WHERE perm_name = ?';
        $parents = $this->_db->getOne($query, array($child));

        if ($parents instanceof PEAR_Error) {
            throw new Horde_Perms_Exception($parents);
        }

        if (empty($parents)) {
            return Horde_Perms::ROOT;
        }

        $parents = explode(':', $parents);
        return array_pop($parents);
    }

    /**
     * Returns a list of parent permissions.
     *
     * @param string $child  The name of the child to retrieve parents for.
     *
     * @return array  A hash with all parents in a tree format.
     * @throws Horde_Perms_Exception
     */
    public function getParents($child)
    {
        $query = 'SELECT perm_parents FROM ' .  $this->_params['table'] .
            ' WHERE perm_name = ?';
        $result = $this->_db->getOne($query, array($child));
        if ($result instanceof PEAR_Error) {
            throw new Horde_Perms_Exception($result);
        } elseif (empty($result)) {
            throw new Horde_Perms_Exception('Does not exist');
        }

        return $this->_getParents($result);
    }

    /**
     * TODO
     */
    protected function _getParents($parents)
    {
        if (empty($parents)) {
            return array(Horde_Perms::ROOT => true);
        }

        $pname = $parents;
        $parents = substr($parents, 0, strrpos($parents, ':'));

        return array($pname => $this->_getParents($parents));
    }

    /**
     * Returns all permissions of the system in a tree format.
     *
     * @return array  A hash with all permissions in a tree format.
     * @throws Horde_Perms_Exception
     */
    public function getTree()
    {
        $query = 'SELECT perm_id, perm_name FROM ' . $this->_params['table'] .
            ' ORDER BY perm_name ASC';
        $tree = $this->_db->getAssoc($query);
        if ($tree instanceof PEAR_Error) {
            throw new Horde_Perms_Exception($tree);
        }

        $tree[Horde_Perms::ROOT] = Horde_Perms::ROOT;
        return $tree;
    }

}
