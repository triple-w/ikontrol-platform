<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
final class IkontrolAdminPasswordSet extends BaseCommand {
 protected $group='iKontrol'; protected $name='ikontrol:admin-password-set'; protected $usage='ikontrol:admin-password-set <email>';
 public function run(array $params){if(count($params)!==1||!filter_var($params[0],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Correo inválido.');$password=rtrim((string)fgets(STDIN),"\r\n");if(strlen($password)<12)throw new \RuntimeException('La contraseña debe tener al menos 12 caracteres.');$db=\Config\Database::connect();$users=$db->table('users')->select('id')->where(['email'=>$params[0],'deleted'=>0,'user_type'=>'staff'])->get()->getResultArray();if(count($users)!==1)throw new \RuntimeException('Administrador inexistente o ambiguo.');$ok=$db->table('users')->where('id',$users[0]['id'])->update(['password'=>password_hash($password,PASSWORD_DEFAULT)]);unset($password);if(!$ok)throw new \RuntimeException('No fue posible actualizar la contraseña.');CLI::write('PASSWORD_UPDATED','green');}
}