<?php
/**
 * WposAdminStock is part of Wallace Point of Sale system (WPOS) API
 *
 * WposAdminStock is used to manage stock
 *
 * WallacePOS is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3.0 of the License, or (at your option) any later version.
 *
 * WallacePOS is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details:
 * <https://www.gnu.org/licenses/lgpl.html>
 *
 * @package    wpos
 * @copyright  Copyright (c) 2014 WallaceIT. (https://wallaceit.com.au)
 * @link       https://wallacepos.com
 * @author     Michael B Wallace <micwallace@gmx.com>
 * @since      File available since 12/04/14 3:44 PM
 */
class WposAdminStock {
    /**
     * @var stdClass provided params
     */
    private $data;
    /**
     * @var StockHistoryModel
     */
    private $histMdl;
    /**
     * @var StockModel
     */
    private $stockMdl;

    /**
     * Decode provided input
     * @param $data
     */
    function __construct($data=null){
        // parse the data and put it into an object
        if ($data!==null){
            $this->data = $data;
        } else {
            $this->data = new stdClass();
        }
        // setup objects
        $this->histMdl = new StockHistoryModel();
        $this->stockMdl = new StockModel();
    }

    /**
     * Import items
     * @param $result
     * @return mixed
     */
    public function importItemsSet($result)
    {
        $_SESSION['import_data'] = $this->data->import_data;
        $_SESSION['import_options'] = $this->data->options;
        return $result;
    }

    /**
     * Import items
     * @param $result
     * @return mixed
     */
    public function importItemsStart($result)
    {
        if (!isset($_SESSION['import_data']) || !is_array($_SESSION['import_data'])){
            $result['error'] = "Import data was not received.";
            EventStream::sendStreamData($result);
            return $result;
        }
        $items = $_SESSION['import_data'];

        EventStream::iniStream();
        $stockMdl = new StockModel();


        EventStream::sendStreamData(['status'=>"Validating Items..."]);
        $jsonval = new JsonValidate($items, '{"storeditemid":1, "locationid":1, "amount":">=1", "reorderpoint":">=1"}');

        $validator = new JsonValidate(null, '{"ID":"1", "locationid":1, "amount":">=1", "reorderpoint":">=1"}');
        $count = 1;
        foreach ($items as $key=>$item){
            EventStream::sendStreamData(['status'=>"Validating Items...", 'progress'=>$count]);

            $validator->validate($item);

            $items[$key] = $item;

            $count++;
        }

        EventStream::sendStreamData(['status'=>"Importing Items..."]);
        $result['data'] = [];
        $count = 1;
        foreach ($items as $item){
            EventStream::sendStreamData(['progress'=>$count]);

            $stockObj = new WposStockItem($item);

            // Check if item exists
            $dupitems = $stockMdl->get($stockObj->storeditemid, $stockObj->locationid);
            if (sizeof($dupitems) > 0) {
                // Update the existing record
                $id = $stockMdl->incrementStockLevel($stockObj->storeditemid, $stockObj->locationid, $stockObj->amount, $stockObj->reorderpoint);
            } else {
                // Add as new entry
                $id = $stockMdl->create($stockObj->storeditemid, $stockObj->locationid, $stockObj->amount, $stockObj->reorderpoint);
            }
            if ($id===false){
                $result['error'] = "Failed to add the item on line ".$count." of the CSV: ".$stockMdl->errorInfo;
                EventStream::sendStreamData($result);
                return $result;
            } else {
                // create history record for added stock
                if ($this->createStockHistory($stockObj->storeditemid, $stockObj->locationid, 'Stock Added', $stockObj->amount)===false){
                    $result['error'] = "Could not create stock history record";
                    EventStream::sendStreamData($result);
                    return $result;
                }
            }
            // Success; log data
            Logger::write("Stock Added", "STOCK", json_encode($stockObj));

            $stockObj->id = $id;
            $result['data'][$id] = $stockObj;

            $count++;
        }

        unset($_SESSION['import_data']);
        unset($_SESSION['import_options']);

        EventStream::sendStreamData($result);
        return $result;
    }

    /**
     * This function is used by WposPosSale and WposInvoices to decrement/increment sold/voided transaction stock; it does not create a history record
     * @param $storeditemid
     * @param $locationid
     * @param $amount
     * @param $reorderpoint
     * @param bool $decrement
     * @return bool
     */
    public function incrementStockLevel($storeditemid, $locationid, $amount, $reorderpoint, $decrement = false){
        if ($this->stockMdl->incrementStockLevel($storeditemid, $locationid, $amount, $reorderpoint, $decrement)!==false){
            return true;
        }
        return false;
    }

    /**
     * Transfer stock to another location
     * @param $result
     * @return mixed
     */
    public function transferStock($result){
        // validate input
        $jsonval = new JsonValidate($this->data, '{"storeditemid":1, "locationid":1, "newlocationid":1, "amount":">=1", "reorderpoint":">=1"}');
        if (($errors = $jsonval->validate())!==true){
            $result['error'] = $errors;
            return $result;
        }
        if ($this->data->locationid == $this->data->newlocationid){
            $result['error'] = "Cannot transfer stock to the same location, pick a different one.";
            return $result;
        }
        // check if theres enough stock at source location
        if (($stock=$this->stockMdl->get($this->data->storeditemid, $this->data->locationid))===false){
            $result['error'] = "Could not fetch current stock level: ".$this->stockMdl->errorInfo;
            return $result;
        }
        if ($stock[0]['stocklevel']<$this->data->amount){
            $result['error'] = "Not enough stock at the source location, add some first";
            return $result;
        }
        // create history record for removed stock
        if ($this->createStockHistory($this->data->storeditemid, $this->data->locationid, 'Stock Transfer', -$this->data->amount, $this->data->newlocationid, 0)===false){ // stock history created with minus
            $result['error'] = "Could not create stock history record";
            return $result;
        }
        // remove stock amount from current location
        if ($this->incrementStockLevel($this->data->storeditemid, $this->data->locationid, $this->data->amount, $this->data->reorderpoint, true)===false){
            $result['error'] = "Could not decrement stock from current location";
            return $result;
        }
        // create history record for added stockd
        if ($this->createStockHistory($this->data->storeditemid, $this->data->newlocationid, 'Stock Transfer', $this->data->amount, $this->data->locationid, 1)===false){
            $result['error'] = "Could not create stock history record";
            return $result;
        }
        // add stock amount to new location
        if ($this->incrementStockLevel($this->data->storeditemid, $this->data->newlocationid, $this->data->amount, $this->data->reorderpoint, false)===false){
            $result['error'] = "Could not add stock to the new location";
            return $result;
        }

        // Success; log data
        Logger::write("Stock Transfer", "STOCK", json_encode($this->data));

        return $result;
    }

    /**
     * Set the level of stock at a location (stocktake)
     * @param $result
     * @return mixed
     */
    public function setStockLevel($result){
        // validate input
        $jsonval = new JsonValidate($this->data, '{"storeditemid":1, "locationid":1, "amount":">=1, "reorderpoint":">=1""}');
        if (($errors = $jsonval->validate())!==true){
            $result['error'] = $errors;
            return $result;
        }
        // create history record for added stock
        if ($this->createStockHistory($this->data->storeditemid, $this->data->locationid, 'Stock Added', $this->data->amount)===false){
            $result['error'] = "Could not create stock history record";
            return $result;
        }
        if ($this->stockMdl->setStockLevel($this->data->storeditemid, $this->data->locationid, $this->data->amount, $this->data->reorderpoint)===false){
            $result['error'] = "Could not add stock to the location";
        }

        // Success; log data
        Logger::write("Stock Level Set", "STOCK", json_encode($this->data));

        return $result;
    }

    /**
     * Set the supplier for an item
     * @param $result
     * @return mixed
     */
    public function editSupplier($result)
    {
        // validate input
        $jsonval = new JsonValidate($this->data, '{"code":"","qty":1, "name":"", "taxid":1, "cost":-1, "price":-1,"type":""}');
        if (($errors = $jsonval->validate()) !== true) {
            $result['error'] = $errors;
            return $result;
        }

        if ($this->stockMdl->editItemSupplier($this->data->id, $this->data) === false) {
            $result['error'] = "Could not edit item supplier.";
        }
        return $result;
    }

    /**
     * Add stock to a location
     * @param $result
     * @return mixed
     */
    public function addStock($result){
        // validate input
        $jsonval = new JsonValidate($this->data, '{"storeditemid":1, "locationid":1, "amount":">=1", "reorderpoint":">=1"}');
        if (($errors = $jsonval->validate())!==true){
            $result['error'] = $errors;
            return $result;
        }
        // create history record for added stock
        if ($this->createStockHistory($this->data->storeditemid, $this->data->locationid, 'Stock Added', $this->data->amount)===false){
            $result['error'] = "Could not create stock history record";
            return $result;
        }
        // add stock amount to new location
        if ($this->incrementStockLevel($this->data->storeditemid, $this->data->locationid, $this->data->amount, $this->data->reorderpoint,false)===false){
            $result['error'] = "Could not add stock to the new location";
            return $result;
        }
        // Success; log data
        Logger::write("Stock Added", "STOCK", json_encode($this->data));
        return $result;
    }

    /**
     * Get stock history records for a specified item & location
     * @param $result
     * @return mixed
     */
    public function getStockHistory($result){
        if (($stockHist = $this->histMdl->get($this->data->storeditemid, $this->data->locationid))===false){
            $result['error']="Could not retrieve stock history";
        } else {
            $result['data']= $stockHist;
        }
        return $result;
    }

    /**
     * Create a stock history record for a item & location
     * @param $storeditemid
     * @param $locationid
     * @param $type
     * @param $amount
     * @param $sourceid
     * @param int $direction
     * @return bool
     */
    private function createStockHistory($storeditemid, $locationid, $type, $amount, $sourceid=-1, $direction=0){
        if ($this->histMdl->create($storeditemid, $locationid, $type, $amount, $sourceid, $direction)!==false){
            return true;
        }
        return false;
    }
} 