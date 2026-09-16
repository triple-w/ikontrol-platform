<?php

namespace App\Models;

class Items_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'items';
        parent::__construct($this->table);
    }

    function get_details($options = array()) {
        $items_table = $this->db->prefixTable('items');
        $order_items_table = $this->db->prefixTable('order_items');
        $item_categories_table = $this->db->prefixTable('item_categories');

        $where = "";
        $id = $this->_get_clean_value($options, "id");
        if ($id) {
            $where .= " AND $items_table.id=$id";
        }

        $search = $this->_get_clean_value($options, "search");
        if ($search) {
            $search = $this->db->escapeLikeString($search);
            $where .= " AND ($items_table.title LIKE '%$search%' ESCAPE '!' OR $items_table.description LIKE '%$search%' ESCAPE '!')";
        }

        $show_in_client_portal = $this->_get_clean_value($options, "show_in_client_portal");
        if ($show_in_client_portal) {
            $where .= " AND $items_table.show_in_client_portal=1";
        }

        $category_id = $this->_get_clean_value($options, "category_id");
        if ($category_id) {
            $where .= " AND $items_table.category_id=$category_id";
        }

        $extra_select = "";
        $login_user_id = $this->_get_clean_value($options, "login_user_id");
        $created_by_hash = $this->_get_clean_value($options, "created_by_hash");
        if ($login_user_id || $created_by_hash) {

            $extra_where = "";
            if ($login_user_id) {
                $extra_where = " AND $order_items_table.created_by=$login_user_id ";
            } else if ($created_by_hash) {
                $extra_where = " AND $order_items_table.created_by_hash='$created_by_hash' ";
            }

            if ($login_user_id && $created_by_hash) {
                $extra_where = " AND ($order_items_table.created_by=$login_user_id OR $order_items_table.created_by_hash='$created_by_hash') ";
            }

            $extra_select = ", (SELECT COUNT($order_items_table.id) FROM $order_items_table WHERE $order_items_table.deleted=0 AND $order_items_table.order_id=0 AND $order_items_table.item_id=$items_table.id $extra_where ) AS added_to_cart";
        }

        $limit_query = "";
        $limit = $this->_get_clean_value($options, "limit");
        if ($limit) {
            $offset = $this->_get_clean_value($options, "offset");
            $limit_query = "LIMIT $offset, $limit";
        }

        //prepare custom fild binding query
        $custom_fields = get_array_value($options, "custom_fields");
        $custom_field_filter = get_array_value($options, "custom_field_filter");
        $custom_field_query_info = $this->prepare_custom_field_query_string("items", $custom_fields, $items_table, $custom_field_filter);
        $select_custom_fieds = get_array_value($custom_field_query_info, "select_string");
        $join_custom_fieds = get_array_value($custom_field_query_info, "join_string");
        $custom_fields_where = get_array_value($custom_field_query_info, "where_string");

        $fiscal_select = ", NULL AS fiscal_status, NULL AS sat_product_code, NULL AS sat_unit_code, NULL AS tax_object_code";
        $fiscal_join = "";
        if ($this->db->tableExists('item_fiscal_settings') && $this->db->tableExists('sat_product_service_keys') && $this->db->tableExists('sat_unit_keys') && $this->db->tableExists('sat_tax_object_codes')) {
            $fiscal_settings = $this->db->prefixTable('item_fiscal_settings');
            $product_keys = $this->db->prefixTable('sat_product_service_keys');
            $unit_keys = $this->db->prefixTable('sat_unit_keys');
            $tax_objects = $this->db->prefixTable('sat_tax_object_codes');
            $fiscal_select = ", $fiscal_settings.status AS fiscal_status, $product_keys.code AS sat_product_code, $unit_keys.code AS sat_unit_code, $tax_objects.code AS tax_object_code";
            $fiscal_join = "LEFT JOIN $fiscal_settings ON $fiscal_settings.id=(SELECT MAX(ifs2.id) FROM $fiscal_settings ifs2 WHERE ifs2.item_id=$items_table.id AND ifs2.deleted=0 AND ifs2.is_default=1)
                LEFT JOIN $product_keys ON $product_keys.id=$fiscal_settings.sat_product_service_key_id
                LEFT JOIN $unit_keys ON $unit_keys.id=$fiscal_settings.sat_unit_key_id
                LEFT JOIN $tax_objects ON $tax_objects.id=$fiscal_settings.tax_object_code_id";
        }

        $sql = "SELECT $items_table.*, $item_categories_table.title as category_title $extra_select $select_custom_fieds $fiscal_select
        FROM $items_table
        LEFT JOIN $item_categories_table ON $item_categories_table.id= $items_table.category_id
        $fiscal_join
        $join_custom_fieds
        WHERE $items_table.deleted=0 $where $custom_fields_where
        ORDER BY $items_table.title ASC
        $limit_query";

        return $this->db->query($sql);
    }
}
