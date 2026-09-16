<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Cfdi40;

use DOMDocument;
use RuntimeException;
use XSLTProcessor;

final class CfdiOriginalChainGenerator
{
    public const VERSION = 'SAT-CFDI40-2026-07-23';
    private string $main;
    private string $base;

    public function __construct(?string $main = null)
    {
        $this->main = $main ?: ROOTPATH . 'resources/fiscal/sat/cfdi40/xslt/cadenaoriginal_4_0.xslt';
        $this->base = dirname($this->main);
    }

    public function generate(string $xml): array
    {
        if (!class_exists(XSLTProcessor::class)) {
            throw new RuntimeException('La extensión PHP XSL no está habilitada.');
        }
        $oldErrors = libxml_use_internal_errors(true);
        try {
            $source = new DOMDocument();
            if (!$source->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new RuntimeException('El XML técnico no está bien formado.');
            }
            $style = new DOMDocument();
            if (!$style->load($this->main, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new RuntimeException('No fue posible cargar la XSLT oficial de cadena original.');
            }
            $resources = $this->resourceMap();
            libxml_set_external_entity_loader(static function (?string $public, ?string $system) use ($resources) {
                $key = preg_replace('#^https://#', 'http://', (string) $system);
                if (!isset($resources[$key])) {
                    return null;
                }
                return fopen($resources[$key], 'rb');
            });
            $processor = new XSLTProcessor();
            if (method_exists($processor, 'setSecurityPrefs')) {
                $processor->setSecurityPrefs(
                    XSL_SECPREF_WRITE_FILE | XSL_SECPREF_CREATE_DIRECTORY
                    | XSL_SECPREF_WRITE_NETWORK | XSL_SECPREF_READ_NETWORK
                );
            }
            if (!$processor->importStylesheet($style)) {
                throw new RuntimeException('No fue posible compilar la XSLT oficial de cadena original.');
            }
            $chain = $processor->transformToXML($source);
            if (!is_string($chain) || trim($chain) === '') {
                throw new RuntimeException('La cadena original resultó vacía.');
            }
            $chain = trim($chain);
            return [
                'chain' => $chain,
                'sha256' => hash('sha256', $chain),
                'xslt_sha256' => hash_file('sha256', $this->main),
                'version' => self::VERSION,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($oldErrors);
            libxml_set_external_entity_loader(null);
        }
    }

    public function manifest(): array
    {
        $files = [$this->main];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base . '/includes'));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'xslt') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return array_map(static fn(string $file): array => [
            'file' => str_replace('\\', '/', substr($file, strlen(ROOTPATH))),
            'sha256' => hash_file('sha256', $file),
        ], $files);
    }

    private function resourceMap(): array
    {
        if (!is_file($this->main)) {
            throw new RuntimeException('No existe la XSLT oficial de cadena original.');
        }
        $map = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base . '/includes'));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'xslt') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->base . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR)));
            $map['http://www.sat.gob.mx/sitio_internet/cfd/' . $relative] = $file->getPathname();
        }
        return $map;
    }
}
