<?php

namespace App\Models;

class Legacy_import_batches_model extends Crud_model
{
    public function __construct($db = null)
    {
        parent::__construct('legacy_import_batches', $db);
    }
}
