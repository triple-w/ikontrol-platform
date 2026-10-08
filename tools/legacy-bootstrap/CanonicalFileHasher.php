<?php

declare(strict_types=1);

namespace Ikontrol\LegacyBootstrap;

use RuntimeException;

/** Cross-platform SHA-256: text line endings become LF; binary bytes remain exact. */
final class CanonicalFileHasher
{
    public const TEXT_MODE = 'text-lf-sha256';
    public const BINARY_MODE = 'binary-sha256';

    /** @var list<string> */
    private const TEXT_EXTENSIONS = [
        'php','json','md','txt','csv','yml','yaml','xml','sql','js','css','html','htm','sh','ps1',
    ];

    public static function mode(string $logicalPath): string
    {
        $extension = strtolower((string)pathinfo(str_replace('\\','/',$logicalPath), PATHINFO_EXTENSION));
        return in_array($extension, self::TEXT_EXTENSIONS, true) ? self::TEXT_MODE : self::BINARY_MODE;
    }

    public static function hashFile(string $file, ?string $logicalPath = null): string
    {
        if (! is_file($file)) throw new RuntimeException('Cannot hash missing file: '.$file);
        $logicalPath ??= $file;
        if (self::mode($logicalPath) === self::BINARY_MODE) return hash_file('sha256',$file);

        $handle = fopen($file,'rb');
        if ($handle === false) throw new RuntimeException('Cannot open file for canonical hashing: '.$file);
        $context = hash_init('sha256');
        $pendingCarriageReturn = false;
        try {
            while (! feof($handle)) {
                $chunk = fread($handle,1024 * 1024);
                if ($chunk === false) throw new RuntimeException('Cannot read file for canonical hashing: '.$file);
                if ($chunk === '') continue;
                if ($pendingCarriageReturn) {
                    $chunk = "\r".$chunk;
                    $pendingCarriageReturn = false;
                }
                if (str_ends_with($chunk,"\r")) {
                    $chunk = substr($chunk,0,-1);
                    $pendingCarriageReturn = true;
                }
                if ($chunk !== '') hash_update($context,str_replace(["\r\n","\r"],["\n","\n"],$chunk));
            }
            if ($pendingCarriageReturn) hash_update($context,"\n");
        } finally {
            fclose($handle);
        }
        return hash_final($context);
    }

    public static function hashString(string $content, string $logicalPath): string
    {
        if (self::mode($logicalPath) === self::BINARY_MODE) return hash('sha256',$content);
        return hash('sha256',str_replace(["\r\n","\r"],["\n","\n"],$content));
    }
}
