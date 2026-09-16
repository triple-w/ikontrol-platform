<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;

final class WarehouseLabelService
{
    public const MAX_QUANTITY = 1000;
    public const MAX_LOGO_BYTES = 2097152;

    private const PRESETS = [
        '50x30' => [50.0, 30.0],
        '60x40' => [60.0, 40.0],
        '100x50' => [100.0, 50.0],
    ];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect('default');
    }

    public function product(int $id): object
    {
        $product = $this->db->table('warehouse_products')->where(['id' => $id, 'deleted' => 0])->get()->getRow();
        if (!$product) {
            throw new InvalidArgumentException('El producto de almacén no existe.');
        }
        return $product;
    }

    public function options(array $input): array
    {
        $preset = (string) ($input['size_preset'] ?? '50x30');
        if (isset(self::PRESETS[$preset])) {
            [$width, $height] = self::PRESETS[$preset];
        } elseif ($preset === 'custom') {
            $width = $this->dimension($input['width_mm'] ?? 0, 'ancho');
            $height = $this->dimension($input['height_mm'] ?? 0, 'alto');
        } else {
            throw new InvalidArgumentException('Seleccione un tamaño de etiqueta válido.');
        }
        if ($width < 25 || $width > 150 || $height < 15 || $height > 100) {
            throw new InvalidArgumentException('El tamaño personalizado debe estar entre 25–150 mm de ancho y 15–100 mm de alto.');
        }

        $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException('La cantidad debe estar entre 1 y '.self::MAX_QUANTITY.'.');
        }

        $fields = [];
        foreach (['logo', 'name', 'model_variant', 'size', 'color', 'barcode', 'barcode_text', 'internal_code'] as $field) {
            $fields[$field] = array_key_exists('fields', $input) ? !empty($input['fields'][$field]) : true;
        }

        return ['width_mm' => $width, 'height_mm' => $height, 'quantity' => $quantity, 'fields' => $fields];
    }

    private function dimension(mixed $value, string $name): float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('Capture un '.$name.' válido en milímetros.');
        }
        return (float) $value;
    }

    public function validEan13(string $code): bool
    {
        if (!preg_match('/^\d{13}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($index = 0; $index < 12; $index++) {
            $sum += (int) $code[$index] * (($index % 2) ? 3 : 1);
        }
        return ((10 - $sum % 10) % 10) === (int) $code[12];
    }

    public function barcodeType(?string $code): ?string
    {
        $code = trim((string) $code);
        return $code === '' ? null : ($this->validEan13($code) ? 'EAN13' : 'C128');
    }

    /** Shared physical metrics used by HTML preview and TCPDF. */
    private function profile(float $width, float $height): array
    {
        $small = $width <= 50 || $height <= 30;
        $large = $width >= 90 && $height >= 45;
        return [
            'margin' => $small ? 1.5 : 2.5,
            'gap' => $small ? .25 : ($large ? .5 : .3),
            'name_font' => $small ? 8 : ($large ? 15 : 11),
            'detail_font' => $small ? 5.5 : ($large ? 9 : 7),
            'code_font' => $small ? 5 : ($large ? 8 : 6.5),
            'logo_height' => $small ? 4 : ($large ? 8 : 5),
            'name_height' => $small ? 3.5 : ($large ? 6 : 4.5),
            'detail_height' => $small ? 2 : ($large ? 3.5 : 2.8),
            'barcode_height' => $small ? 7 : ($large ? 12 : 9),
            'code_height' => $small ? 2 : ($large ? 3 : 2.8),
            'barcode_width_ratio' => .88,
        ];
    }

    public function logoDirectory(): string
    {
        return rtrim(FCPATH, '/\\').DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.'warehouse_product_labels'.DIRECTORY_SEPARATOR;
    }

    public function logoPath(object $product): ?string
    {
        $path = $this->logoDirectory().basename((string) ($product->label_logo ?? ''));
        return !empty($product->label_logo) && is_file($path) ? $path : null;
    }

    /** Canonical data builder and layout definition for both renderers. */
    public function prepare(int $id, array $input): array
    {
        $product = $this->product($id);
        $options = $this->options($input);
        $profile = $this->profile($options['width_mm'], $options['height_mm']);
        $logoPath = $this->logoPath($product);
        $barcodeType = $this->barcodeType($product->barcode);
        $blocks = $this->layoutBlocks($product, $options['fields'], $profile, $logoPath, $barcodeType);
        [$blocks, $profile] = $this->fitLayout($blocks, $profile, $options['height_mm'] - 2 * $profile['margin']);
        $blocks = $this->positionBlocks($blocks, $profile, $options['width_mm']);

        return [
            'product' => $product,
            'options' => $options,
            'profile' => $profile,
            'logo_path' => $logoPath,
            'barcode_type' => $barcodeType,
            'blocks' => $blocks,
        ];
    }

    private function fitLayout(array $blocks, array $profile, float $availableHeight): array
    {
        $contentHeight = array_sum(array_column($blocks, 'height'));
        $gapCount = max(0, count($blocks) - 1);
        $totalHeight = $contentHeight + $gapCount * $profile['gap'];
        if ($totalHeight <= $availableHeight || $totalHeight <= 0) {
            return [$blocks, $profile];
        }

        $scale = $availableHeight / $totalHeight;
        $profile['gap'] *= $scale;
        foreach ($blocks as &$block) {
            $block['height'] *= $scale;
            if (isset($block['font'])) {
                $block['font'] *= $scale;
            }
        }
        unset($block);
        return [$blocks, $profile];
    }

    private function positionBlocks(array $blocks, array $profile, float $labelWidth): array
    {
        $usableWidth = $labelWidth - 2 * $profile['margin'];
        $y = $profile['margin'];
        foreach ($blocks as $index => &$block) {
            if ($index > 0) {
                $y += $profile['gap'];
            }
            $width = $usableWidth;
            if ($block['type'] === 'barcode') {
                $width *= $block['width_ratio'];
            } elseif ($block['type'] === 'logo') {
                $width *= .55;
            }
            $block['visible'] = true;
            $block['x_mm'] = ($labelWidth - $width) / 2;
            $block['y_mm'] = $y;
            $block['width_mm'] = $width;
            $block['height_mm'] = $block['height'];
            $block['alignment'] = 'center';
            $block['font_size_pt'] = $block['font'] ?? null;
            $block['font_weight'] = !empty($block['bold']) ? 'bold' : 'normal';
            $y += $block['height'];
        }
        unset($block);
        return $blocks;
    }
    private function layoutBlocks(object $product, array $fields, array $profile, ?string $logoPath, ?string $barcodeType): array
    {
        $blocks = [];
        if ($fields['logo'] && $logoPath) {
            $blocks[] = ['type' => 'logo', 'height' => $profile['logo_height'], 'path' => $logoPath, 'align' => 'center'];
        }
        if ($fields['name']) {
            $blocks[] = ['type' => 'text', 'role' => 'name', 'height' => $profile['name_height'], 'font' => $profile['name_font'], 'bold' => true, 'value' => $this->clip((string) $product->name, 70), 'align' => 'center'];
        }

        $modelVariant = trim(implode(' / ', array_filter([(string) $product->model, (string) $product->variant])));
        if ($fields['model_variant'] && $modelVariant !== '') {
            $blocks[] = ['type' => 'text', 'role' => 'detail', 'height' => $profile['detail_height'], 'font' => $profile['detail_font'], 'bold' => false, 'value' => $this->clip($modelVariant, 55), 'align' => 'center'];
        }

        $attributes = [];
        if ($fields['color'] && !empty($product->color)) {
            $attributes[] = $product->color;
        }
        if ($fields['size'] && !empty($product->size)) {
            $attributes[] = 'Talla '.$product->size;
        }
        if ($attributes) {
            $blocks[] = ['type' => 'text', 'role' => 'detail', 'height' => $profile['detail_height'], 'font' => $profile['detail_font'], 'bold' => false, 'value' => $this->clip(implode(' · ', $attributes), 55), 'align' => 'center'];
        }

        if ($fields['barcode'] && !empty($product->barcode)) {
            $blocks[] = ['type' => 'barcode', 'height' => $profile['barcode_height'], 'width_ratio' => $profile['barcode_width_ratio'], 'value' => (string) $product->barcode, 'barcode_type' => (string) $barcodeType, 'align' => 'center'];
            if ($fields['barcode_text']) {
                $blocks[] = ['type' => 'text', 'role' => 'barcode_text', 'height' => $profile['code_height'], 'font' => $profile['code_font'], 'bold' => false, 'value' => (string) $product->barcode, 'align' => 'center'];
            }
        } elseif ($fields['barcode']) {
            $blocks[] = ['type' => 'text', 'role' => 'warning', 'height' => $profile['detail_height'], 'font' => $profile['detail_font'], 'bold' => false, 'value' => 'Sin código de barras', 'align' => 'center'];
        }

        if ($fields['internal_code']) {
            $blocks[] = ['type' => 'text', 'role' => 'internal_code', 'height' => $profile['code_height'], 'font' => $profile['code_font'], 'bold' => true, 'value' => $this->clip((string) $product->internal_code, 50), 'align' => 'center'];
        }
        return $blocks;
    }

    public function renderPreview(int $id, array $input): string
    {
        $data = $this->prepare($id, $input);
        $options = $data['options'];
        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = '';

        foreach ($data['blocks'] as $block) {
            $style = 'left:'.($block['x_mm'] / $options['width_mm'] * 100).'%;top:'.($block['y_mm'] / $options['height_mm'] * 100).'%;width:'.($block['width_mm'] / $options['width_mm'] * 100).'%;height:'.($block['height_mm'] / $options['height_mm'] * 100).'%;';
            if ($block['type'] === 'logo') {
                $mime = $this->logoMimeType($block['path']);
                $html .= '<div class="wl-block wl-logo-block" style="'.$style.'"><img class="wl-logo" alt="" src="data:'.$escape($mime).';base64,'.base64_encode((string) file_get_contents($block['path'])).'"></div>';
            } elseif ($block['type'] === 'barcode') {
                $html .= '<div class="wl-block wl-barcode-block" style="'.$style.'">'.$this->svg($block['value'], $block['barcode_type']).'</div>';
            } else {
                $fontMm = (float) $block['font_size_pt'] * 0.352778;
                $fontStyle = 'font-size:calc('.($fontMm / $options['width_mm'] * 100).' * 1cqw);font-weight:'.($block['font_weight'] === 'bold' ? '700' : '400').';';
                $html .= '<div class="wl-block wl-text wl-'.$escape($block['role']).'" style="'.$style.$fontStyle.'">'.$escape($block['value']).'</div>';
            }
        }

        return '<div class="warehouse-label-preview" data-layout="physical-mm" data-width-mm="'.$options['width_mm'].'" data-height-mm="'.$options['height_mm'].'" style="aspect-ratio:'.$options['width_mm'].'/'.$options['height_mm'].'">'.$html.'</div>';
    }

    private function logoMimeType(string $path): string
    {
        if (!extension_loaded('fileinfo') || !class_exists('\finfo')) {
            log_message('error', 'La extensión PHP fileinfo no está disponible para el preview de etiquetas.');
            throw new RuntimeException('No fue posible validar la imagen del logo.');
        }
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        } catch (\Throwable $exception) {
            log_message('error', 'No fue posible validar el logo de etiqueta: '.$exception->getMessage());
            throw new RuntimeException('No fue posible validar la imagen del logo.');
        }
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            log_message('error', 'MIME no permitido en logo de etiqueta: '.(string) $mime);
            throw new RuntimeException('No fue posible validar la imagen del logo.');
        }
        return (string) $mime;
    }

    private function svg(string $code, string $type): string
    {
        require_once APPPATH.'ThirdParty/tcpdf/tcpdf_barcodes_1d.php';
        return '<div class="wl-barcode">'.(new \TCPDFBarcode($code, $type))->getBarcodeSVGcode(1.2, 28, 'black').'</div>';
    }

    public function pdf(int $id, array $input): string
    {
        $data = $this->prepare($id, $input);
        $options = $data['options'];
        $profile = $data['profile'];
        $this->ensureTcpdfCache();
        require_once APPPATH.'ThirdParty/tcpdf/tcpdf.php';
        $orientation = $options['width_mm'] > $options['height_mm'] ? 'L' : 'P';
        $pdf = new \TCPDF($orientation, 'mm', [$options['width_mm'], $options['height_mm']], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins($profile['margin'], $profile['margin'], $profile['margin']);
        $pdf->SetAutoPageBreak(false, 0);
        $usableWidth = $options['width_mm'] - 2 * $profile['margin'];

        for ($page = 0; $page < $options['quantity']; $page++) {
            $pdf->AddPage($orientation, [$options['width_mm'], $options['height_mm']]);
            foreach ($data['blocks'] as $block) {
                if ($block['type'] === 'logo') {
                    $size = @getimagesize($block['path']);
                    $ratio = $size && $size[1] ? $size[0] / $size[1] : 2;
                    $width = min($block['width_mm'], $block['height_mm'] * $ratio);
                    $x = $block['x_mm'] + ($block['width_mm'] - $width) / 2;
                    $pdf->Image($block['path'], $x, $block['y_mm'], $width, $block['height_mm'], '', '', '', true, 300);
                } elseif ($block['type'] === 'barcode') {
                    $pdf->write1DBarcode($block['value'], $block['barcode_type'], $block['x_mm'], $block['y_mm'], $block['width_mm'], $block['height_mm'], .35, ['align' => 'C', 'stretch' => true, 'fitwidth' => true, 'border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false, 'text' => false], 'N');
                } else {
                    $x = (float) $block['x_mm'];
                    $y = (float) $block['y_mm'];
                    $width = (float) $block['width_mm'];
                    $height = (float) $block['height_mm'];
                    $fontSize = (float) $block['font_size_pt'];
                    $text = trim((string) $block['value']);
                    if ($text === '' || $fontSize <= 0) {
                        continue;
                    }
                    if ($x < 0 || $y < 0 || $width <= 0 || $height <= 0 || $x + $width > $options['width_mm'] + .000001 || $y + $height > $options['height_mm'] + .000001) {
                        throw new RuntimeException('La geometría del bloque de texto está fuera de la etiqueta.');
                    }
                    $pdf->SetAlpha(1);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->SetFont('dejavusans', $block['font_weight'] === 'bold' ? 'B' : '', $fontSize);
                    $pdf->SetXY($x, $y);
                    $pdf->Cell($width, $height, $text, 0, 0, 'C', false, '', 1, true, 'T', 'M');
                }
            }
        }

        $bytes = $pdf->Output('', 'S');
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF')) {
            throw new RuntimeException('No fue posible generar el PDF de etiquetas.');
        }
        return $bytes;
    }

    private function ensureTcpdfCache(): void
    {
        $directory = rtrim(WRITEPATH, '/\\').DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'tcpdf'.DIRECTORY_SEPARATOR;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el caché de PDF.');
        }
        if (!defined('K_PATH_CACHE')) {
            define('K_PATH_CACHE', $directory);
        }
    }

    private function clip(string $value, int $maximum): string
    {
        return mb_strlen($value, 'UTF-8') > $maximum ? mb_substr($value, 0, $maximum - 1, 'UTF-8').'…' : $value;
    }
}
