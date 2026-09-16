<?php
declare(strict_types=1);
namespace App\Services\Security;
use RuntimeException;
final class HiddenPasswordReader{public function read(string$prompt):string{fwrite(STDOUT,$prompt);if(PHP_OS_FAMILY==='Windows'){$command='powershell.exe -NoProfile -NonInteractive -Command ' .'"$s=Read-Host -AsSecureString; $b=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); try {[Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)} finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b)}"';$value=shell_exec($command);}else{shell_exec('stty -echo');try{$value=fgets(STDIN);}finally{shell_exec('stty echo');}}fwrite(STDOUT,PHP_EOL);if(!is_string($value))throw new RuntimeException('No fue posible leer la contraseña de forma oculta.');return rtrim($value,"\r\n");}}
