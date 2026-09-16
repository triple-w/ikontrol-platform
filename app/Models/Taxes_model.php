<?php

namespace App\Models;

class Taxes_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'taxes';
        parent::__construct($this->table);
    }

    function get_details($options = array()) {
        $taxes_table = $this->db->prefixTable('taxes');
        $where = "";
        $id = $this->_get_clean_value($options, "id");
        if ($id) {
            $where = " AND $taxes_table.id=$id";
        }

        $codes_table = $this->db->prefixTable('sat_tax_codes');
        $factors_table = $this->db->prefixTable('sat_tax_factor_types');
        $fiscalSelect = $this->db->tableExists('sat_tax_codes') ? ", $codes_table.code AS sat_tax_code, $factors_table.name AS factor_type" : ", NULL AS sat_tax_code, NULL AS factor_type";
        $fiscalJoin = $this->db->tableExists('sat_tax_codes') ? " LEFT JOIN $codes_table ON $codes_table.id=$taxes_table.sat_tax_code_id LEFT JOIN $factors_table ON $factors_table.id=$taxes_table.factor_type_id" : "";
        $sql = "SELECT $taxes_table.* $fiscalSelect
        FROM $taxes_table
        $fiscalJoin
        WHERE $taxes_table.deleted=0 $where";
        return $this->db->query($sql);
    }

}
