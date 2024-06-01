<?php

require_once('CONSTANTS.php');

class DataBase {
    
    public $db;
    public $type;
    public $table = "ShoppingList";
    
    /**
     * Constructor
     */
    function __construct($dbtype, $dbargs) 
    {
        $this->type = $dbtype;

        switch($dbtype) {
            case 'SQLite':
                $db_pdo="sqlite:".$dbargs['file'];

                try{
                    $this->db = new PDO($db_pdo);
                } catch(PDOException $e){
                    die(json_encode([
                        'type'    => API_ERROR_DATABASE_CONNECT,
                        'content' => $e->getMessage(),
                    ]));
                }
                break;
            case 'MySQL':
                $db_pdo="mysql:host=".$dbargs['host'].";dbname=".$dbargs['db'];

                try {
                    $this->db = new PDO($db_pdo, $dbargs['user'], $dbargs['password']);
                } catch(PDOException $e){
                    die(json_encode([
                        'type'    => API_ERROR_DATABASE_CONNECT,
                        'content' => $e->getMessage(),
                    ]));
                }
                break;
            default:
                die(json_encode([
                    'type'    => API_ERROR_MISSING_PARAMETER,
                    'content' => 'Missing database parameters.',
                ]));
        }
        
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    
    /**
     * Create table
     * 
     * @return void
     */
    function init(): void
    {
        $sql = "CREATE table {$this->table}(
            item STRING PRIMARY KEY,
            count INT NOT NULL,
            checked INT NOT NULL,
            category STRING);";
        try {
            $this->db->exec($sql);
        } catch(PDOException $e){
            //die(json_encode(array('type' => API_ERROR_UNKNOWN, 'content' => $e->getMessage()))); //uncomment after init() has been put to INSTALL.php
        }
    }
    
    /**
     * List all items
     * 
     * @return string
     */
    function listall(): string
    {
        try{
            $sql   = "SELECT * FROM {$this->table} ORDER BY item ASC";
            $val   = $this->db->query($sql);
            $stack = [];

            foreach($val as $row) {
                $stack = [
                    'itemTitle'    => $row['item'],
                    'itemCount'    => $row['count'],
                    'checked'      => (bool)$row['checked'],
                    'itemCategory' => $row['category']','
                ];
            }

            if(count($stack) == 0) {
                return json_encode([
                    'type' => API_SUCCESS_LIST_EMPTY,
                ]);
            } else {
                return json_encode([
                    'type'  => API_SUCCESS_LIST,
                    'items' => $stack,
                ]);
            }
        }catch(PDOException $e){
            return json_encode([
                'type'    => API_ERROR_LIST,
                'content' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Check if item exists
     * 
     * @return bool
     */
    function exists($item): bool 
    {
        $stmt = $this->db->prepare("SELECT * from $this->table WHERE item=:item;");
        $stmt->bindParam(':item', $item, PDO::PARAM_STR);
        $stmt->execute();

        return (bool)count($stmt->fetchAll());
    }
    
    /**
     * Save item
     * 
     * @param $item
     * @param $count
     * @return string
     */
    function save($item, $count): string
    {
        try{
            $checked = (int)false;
            $stmt = $this->db->prepare("INSERT INTO $this->table (item, count, checked) VALUES (:item, :count, :checked);");
            $stmt->bindParam(':item', $item, PDO::PARAM_STR);
            $stmt->bindParam(':count', $count, PDO::PARAM_INT);
            $stmt->bindParam(':checked', $checked, PDO::PARAM_INT);
            $stmt->execute();

            return json_encode([
                'type'    => API_SUCCESS_SAVE,
                'content' => $item.' saved.',
            ]);
        } catch(PDOException $e) {
            return json_encode([
                'type'    => API_ERROR_SAVE,
                'content' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Save multiple data
     * 
     * @param string $jsonData
     * @return string
     */
    function saveMultiple(string $jsonData): string
    {
        if(empty($jsonData)){
            die(json_encode([
                'type'    => API_ERROR_MISSING_PARAMETER,
                'content' => 'parameter missing for saveMultiple',
            ]));
        }

        $itemList = json_decode($jsonData, true);
        $success  = true;
        $errors   = [];

        foreach($itemList as $item) {
            if ($this->exists($item['itemTitle'])) {
                $output = $this->update($item['itemTitle'], $item['itemCount']);
            } else {
                $output = $this->save($item['itemTitle'], $item['itemCount']);
            }

            $output = json_decode($output, true);
            
            if($output['type'] != API_SUCCESS_SAVE && $output['type'] != API_SUCCESS_UPDATE) {
                $success = false;

                $errors[] = [
                    'itemTitle' => $item['itemTitle'],
                    'error'     => $output['content'],
                ];
            }
        }

        if($success) {
            return json_encode([
                'type'    => API_SUCCESS_SAVE, 
                'content' => count($itemList)>1 ? 'Multiple items saved.':$itemList[0]['itemTitle'].' saved.',
            ]);
        } else {
            return json_encode([
                'type'    => API_ERROR_SAVE,
                'content' => $errors,
            ]);
        }
    }
    
    /**
     * Update item
     * 
     * @param $item
     * @param $count
     * @return string
     */
    function update($item, $count): string
    {
        try{
            $stmt = $this->db->prepare("UPDATE $this->table SET count=:count WHERE item=:item;");
            $stmt->bindParam(':item', $item, PDO::PARAM_STR);
            $stmt->bindParam(':count', $count, PDO::PARAM_INT);
            $stmt->execute();

            return json_encode([
                'type'    => API_SUCCESS_UPDATE,
                'content' => 'Update successfull.',
            ]);
        } catch(PDOException $e) {
            return json_encode([
                'type'    => API_ERROR_UPDATE_,
                'content' => $e->getMessage(),
            ]);
        }
    }
    
    /** 
     * Delete multiple items
     * 
     * @param string $jsonData
     * @return string
     */
    function deleteMultiple($jsonData): string
    {
        if(empty($jsonData)) {
            die(json_encode([
                'type'    => API_ERROR_MISSING_PARAMETER,
                'content' => 'parameter missing for deleteMultiple',
            ]));
        }

        $itemList = json_decode($jsonData, true);
        $success  = true;
        $errors   = [];

        foreach($itemList as $item) {
            $output = json_decode($this->delete($item['itemTitle']), true);
            
            if($output['type']!=API_SUCCESS_DELETE) {
                $success = false;

                $errors[] = [
                    'itemTitle' => $item['itemTitle'],
                    'error'     => $output['content'],
                ];
            }
        }

        if($success) {
            return json_encode([
                'type'    => API_SUCCESS_DELETE, 
                'content' => count($itemList)>1 ? 'Multiple items deleted.':$itemList[0]['itemTitle'].' deleted.',
            ]);
        } else {
            return json_encode([
                'type'    => API_ERROR_DELETE,
                'content' => $errors,
            ]);
        }
    }
    
    /** 
     * Delete item
     * 
     * @param $item
     * @return string
     */
    function delete($item){
        try{
            $stmt = $this->db->prepare("DELETE FROM $this->table WHERE item=:item");
            $stmt->bindParam(':item', $item, PDO::PARAM_STR);
            $stmt->execute();

            return json_encode([
                'type'    => API_SUCCESS_DELETE,
                'content' => 'Item deleted.',
            ]);
        } catch(PDOException $e) {
            return json_encode([
                'type'    => API_ERROR_DELETE,
                'content' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Clear database
     */
    function clear(){
        try{
            $stmt = $this->db->exec("TRUNCATE TABLE $this->table;");

            return json_encode([
                'type'    => API_SUCCESS_CLEAR,
                'content' => 'Database cleared.',
            ]);
        } catch(PDOException $e) {
            return json_encode([
                'type'    => API_ERROR_CLEAR,
                'content' => $e->getMessage(),
            ]);
        }
    }

}
