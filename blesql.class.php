<?php
/**********************************************************************
*  Author: Quentin Ligier (quentin.ligier at bluewin.ch)
*  Name..: BleSQL
*  Desc..: BleSQL est un singleton et wrapper pour MySQLi
*
*/
class BleSQLException extends Exception {}

class BleSQL {

    protected static $_instance;

    protected static $_host;
    protected static $_username;
    protected static $_password;
    protected static $_db;
    protected static $_port;

    protected static $num_queries     = 0;
    protected static $time_queries    = 0;

    protected $mysqli_authorized_vars = ['affected_rows', 'connect_errno', 'connect_error', 'errno', 'error', 'error_list', 'insert_id'];
    protected $blesql_authorized_vars = ['num_queries', 'time_queries'];

    const FETCH_ASSOC = 1;
    const FETCH_NUM   = 2;

    /* ****************************************************************************************** */
    /* PUBLIC METHODS */

    public function __construct() {}

    public function __destruct() {}

    public function __get($name) {
        if (in_array($name, $this->mysqli_authorized_vars))
            return $this->get()->{$name};
        if (in_array($name, $this->blesql_authorized_vars))
            return self::${$name};

        return false;
    }

    public function setConfig($host, $username, $password, $db, $port = 3306) {
        self::$_host = $host;
        self::$_username = $username;
        self::$_password = $password;
        self::$_db = $db;
        self::$_port = (int)$port;
    }

    /* MySQLi function */
    public function query($query, $resultmode = MYSQLI_STORE_RESULT) {
        ++self::$num_queries;
        $time_start = microtime(true);
        $results = $this->get()->query($query, $resultmode);
        self::$time_queries += microtime(true) - $time_start;
        return $results;
    }

    /* New methods */
    public function getResults($query, $datas = []) {
        return (array)$this->stmtQuery($query, $datas, self::FETCH_ASSOC);
    }

    public function getRow($query, $datas = []) {
        $results = $this->stmtQuery($query, $datas, self::FETCH_ASSOC);
        return (isset($results[0])) ? $results[0] : false;
    }

    public function getVar($query, $datas = []) {
        $results = $this->stmtQuery($query, $datas, self::FETCH_NUM);
        return (isset($results[0][0])) ? $results[0][0] : false;
    }

    public function getCol($query, $datas = []) {
        $results = $this->stmtQuery($query, $datas, self::FETCH_NUM);
        $results_col = array();
        foreach ($results AS $row)
            $results_col[] = $row[0];
        return $results_col;
    }

    public function insert($table_name, $datas) {
        $cols = array_keys($datas);
        foreach ($cols AS &$col)
            $col = "`".$col."`";

        $query = 'INSERT INTO '.$table_name.'('.implode(',', $cols).') VALUES('.implode(',', array_fill(0, count($datas), '?')).')';
        return $this->stmtQuery($query, array_values($datas));
    }

    public function update($table_name, $datas, $where) {
        $data_cols = array_keys($datas);
        $where_cols = array_keys($where);

        foreach ($data_cols AS &$col)
            $col = "`".$col."`=?";
        foreach ($where_cols AS &$col)
            $col = "`".$col."`=?";

        $query = 'UPDATE '.$table_name.' SET '.implode(',', $data_cols).' WHERE '.implode(' AND ', $where_cols);
        return (bool)$this->stmtQuery($query, array_merge(array_values($datas), array_values($where)));
    }

    public function delete($table_name, $where) {
        $where_cols = array_keys($where);
        foreach ($where_cols AS &$col)
            $col = "`".$col."`=?";

        $query = 'DELETE FROM '.$table_name.' WHERE '.implode(' AND', $where_cols);
        return (bool)$this->stmtQuery($query, array_values($where));
    }

    public function execute($query, $datas = array()) {
        return $this->stmtQuery($query, $datas);
    }



    /* ****************************************************************************************** */
    /* PROTECTED METHODS */

    protected function get() {
        if (!$this->hasInstance())
            $this->connect();
        return self::$_instance;
    }

    protected function connect() {
        if ($this->hasInstance())
            return;

        self::$_instance = new mysqli(self::$_host, self::$_username, self::$_password, self::$_db, self::$_port);
        if (!self::$_instance)
            throw new BleSQLException('Erreur lors de l\'ouverture d\'une connexion SQL');

        self::$_instance->set_charset('utf8');
    }

    protected function hasInstance() {
        return isset(self::$_instance) && null !== self::$_instance;
    }

    protected function stmtQuery($query, $datas, $type_fetch = self::FETCH_ASSOC) {
        if (substr_count($query, '?') !== count($datas))
            throw new BleSQLException('Le nombre de paramètres de la requête préparée est incorrect');

        // Préparation
        ++self::$num_queries;
        $time_start = microtime(true);
        $stmt = $this->get()->prepare($query);
        if (false === $stmt)
            throw new BleSQLException('Impossible de préparer la requête : '.$this->get()->error);

        // Paramètres
        if (0 < count($datas)) {
            $params = [''];
            foreach ($datas AS &$value) {
                $params[0] .= $this->stmtGetType($value);
                $params[] = &$value;
            }
            call_user_func_array(array(&$stmt, 'bind_param'), $params);
        }

        // Exécution
        $success = $stmt->execute();

        // Pas de résultats
        if (false === $success || false === $stmt->result_metadata()) {
            $return = false;
            if (true === $success && $stmt->affected_rows > 0)
                    $return = ($stmt->insert_id > 0) ? $stmt->insert_id : true;
            $stmt->close();
            return $return;
        }

        // Résultats
        $stmt->store_result();
        if (self::FETCH_ASSOC === $type_fetch)
            $results = $this->stmtFetchAssoc($stmt);
        elseif (self::FETCH_NUM === $type_fetch)
            $results = $this->stmtFetchNum($stmt);

        $stmt->close();
        self::$time_queries += microtime(true) - $time_start;

        return $results;
    }

    protected function stmtFetchAssoc(mysqli_stmt &$stmt) {
        $results = [];
        $variables = [];
        $data = [];
        $meta = $stmt->result_metadata();

        while ($field = $meta->fetch_field())
            $variables[] = &$data[$field->name];

        call_user_func_array(array($stmt, "bind_result"), $variables);
        $copy = create_function('$a', 'return $a;');
        while ($stmt->fetch())
            $results[] = array_map($copy, $data);

        return $results;
    }

    protected function stmtFetchNum(mysqli_stmt &$stmt) {
        $results = [];
        $variables = [];
        $meta = $stmt->result_metadata();

        while ($field = $meta->fetch_field())
            $variables[] = &$data[$field->name];

        call_user_func_array(array($stmt, "bind_result"), $variables);
        $copy = create_function('$a', 'return $a;');
        while ($stmt->fetch())
            $results[] = array_map($copy, array_values($data));

        return $results;
    }

    protected function stmtGetType($var) {
        if (is_int($var))
            return 'i';
        if (is_float($var))
            return 'd';
        return 's';
    }


}