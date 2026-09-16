<?php
declare(strict_types=1);
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateFiscalDraftCatalogs extends Migration
{
    public function up(): void
    {
        $catalogs=[
            'sat_payment_forms'=>[
                'fields'=>['code'=>['type'=>'VARCHAR','constraint'=>3],'name'=>['type'=>'VARCHAR','constraint'=>120]],
                'rows'=>[['01','Efectivo'],['03','Transferencia electrónica de fondos'],['04','Tarjeta de crédito'],['28','Tarjeta de débito'],['99','Por definir']]],
            'sat_payment_methods'=>[
                'fields'=>['code'=>['type'=>'VARCHAR','constraint'=>3],'name'=>['type'=>'VARCHAR','constraint'=>120]],
                'rows'=>[['PUE','Pago en una sola exhibición'],['PPD','Pago en parcialidades o diferido']]],
            'sat_currencies'=>[
                'fields'=>['code'=>['type'=>'CHAR','constraint'=>3],'name'=>['type'=>'VARCHAR','constraint'=>120],'requires_exchange_rate'=>['type'=>'TINYINT','constraint'=>1,'default'=>1]],
                'rows'=>[['MXN','Peso Mexicano',0],['USD','Dólar estadounidense',1],['EUR','Euro',1]]],
        ];
        foreach($catalogs as$table=>$definition){
            if(!$this->db->tableExists($table)){
                $this->forge->addField(['id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true]]+$definition['fields']+[
                    'is_active'=>['type'=>'TINYINT','constraint'=>1,'default'=>1],
                    'created_at'=>['type'=>'DATETIME','null'=>true],
                    'updated_at'=>['type'=>'DATETIME','null'=>true],
                ]);
                $this->forge->addKey('id',true);$this->forge->addUniqueKey('code',"uq_{$table}_code");$this->forge->createTable($table);
            }
            foreach($definition['rows'] as$row){
                $data=['code'=>$row[0],'name'=>$row[1],'is_active'=>1];
                if($table==='sat_currencies')$data['requires_exchange_rate']=$row[2];
                if(!$this->db->table($table)->where('code',$row[0])->countAllResults())$this->db->table($table)->insert($data+['created_at'=>date('Y-m-d H:i:s')]);
            }
        }
    }
    public function down(): void
    {
        foreach(['sat_currencies','sat_payment_methods','sat_payment_forms']as$table)if($this->db->tableExists($table))$this->forge->dropTable($table);
    }
}
