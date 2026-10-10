<?php
// =============================================================================
// EVOLVIX GLOBAL · Exportación para la asesoría
//
//   ?tipo=emitidas|recibidas &anio=2026 &trimestre=1..4 (0 = año entero)
//   &marca=ID (opcional) &formato=csv|zip
//
// CSV: el libro registro, listo para abrir en Excel (separador ";", coma
// decimal, UTF-8 con BOM). ZIP: el mismo CSV más todos los PDF/fotos
// originales del periodo, con un nombre que se entiende (fecha_número_...).
// Solo entran facturas contabilizadas; los borradores por revisar, no.
// =============================================================================

require_once __DIR__ . '/seguridad.php';
evolvixRequiereSesion();
$pdo = evolvixConectar();

$tipo = ($_GET['tipo'] ?? '') === 'emitidas' ? 'emitidas' : 'recibidas';
$anio = (int)($_GET['anio'] ?? date('Y'));
$trimestre = max(0, min(4, (int)($_GET['trimestre'] ?? 0)));
$marca = (int)($_GET['marca'] ?? 0);
$formato = ($_GET['formato'] ?? 'csv') === 'zip' ? 'zip' : 'csv';
if ($anio < 2000 || $anio > 2100) $anio = (int)date('Y');

$desde = $trimestre ? sprintf('%04d-%02d-01', $anio, ($trimestre - 1) * 3 + 1) : "$anio-01-01";
$hasta = $trimestre ? date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anio, $trimestre * 3))) : "$anio-12-31";

// --- Filas del libro -----------------------------------------------------------
$filtroMarca = $marca > 0 ? ' AND d.marca_id = ' . $marca : '';
$stmt = $pdo->prepare(
  "SELECT d.id, 'Subida' AS origen, d.fecha, d.numero, m.nombre AS marca, d.contraparte, d.nif, d.concepto, d.categoria,
          d.base, d.iva_pct, d.cuota_iva, d.irpf_pct, d.cuota_irpf, d.total, d.estado, d.fecha_pago, d.vencimiento,
          d.archivo, d.archivo_tipo
   FROM documentos d JOIN marcas m ON m.id = d.marca_id
   WHERE d.contabilizado = 1 AND d.tipo = ? AND d.fecha BETWEEN ? AND ? $filtroMarca"
);
$stmt->execute([$tipo === 'emitidas' ? 'emitida' : 'recibida', $desde, $hasta]);
$filas = $stmt->fetchAll();

if ($tipo === 'emitidas') {
  $filtroMarcaF = $marca > 0 ? ' AND f.marca_id = ' . $marca : '';
  $stmt = $pdo->prepare(
    "SELECT f.id, 'CRM' AS origen, f.fecha_emision AS fecha, f.numero, m.nombre AS marca, c.nombre AS contraparte, c.cif AS nif,
            (SELECT GROUP_CONCAT(l.descripcion ORDER BY l.orden SEPARATOR ' · ') FROM factura_lineas l WHERE l.factura_id = f.id) AS concepto,
            '' AS categoria, f.base, f.iva AS iva_pct, ROUND(f.total - f.base, 2) AS cuota_iva, 0 AS irpf_pct, 0 AS cuota_irpf,
            f.total, f.estado, f.fecha_pago, NULL AS vencimiento, NULL AS archivo, '' AS archivo_tipo
     FROM facturas f JOIN marcas m ON m.id = f.marca_id JOIN clientes c ON c.id = f.cliente_id
     WHERE f.fecha_emision BETWEEN ? AND ? $filtroMarcaF"
  );
  $stmt->execute([$desde, $hasta]);
  $filas = array_merge($filas, $stmt->fetchAll());
}
usort($filas, fn($a, $b) => [$a['fecha'], $a['numero']] <=> [$b['fecha'], $b['numero']]);

// --- CSV -------------------------------------------------------------------------
function evolvixCeldaTexto($v): string {
  $v = (string)$v;
  // Evita que Excel interprete un texto como fórmula (inyección CSV).
  if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;
  return $v;
}
function evolvixCeldaImporte($v): string { return number_format((float)$v, 2, ',', ''); }
function evolvixCeldaFecha($v): string { return $v ? date('d/m/Y', strtotime($v)) : ''; }

$esEmitidas = $tipo === 'emitidas';
$cabecera = ['Fecha', 'Nº factura', 'Marca', $esEmitidas ? 'Cliente' : 'Proveedor', 'NIF', 'Concepto'];
if (!$esEmitidas) $cabecera[] = 'Categoría';
$cabecera = array_merge($cabecera, ['Base imponible', '% IVA', 'Cuota IVA', '% IRPF', 'Retención IRPF', 'Total',
  'Estado', $esEmitidas ? 'Fecha cobro' : 'Fecha pago', 'Vencimiento', 'Origen', 'Archivo']);

$nombresArchivo = [];
$csv = fopen('php://temp', 'w+');
fwrite($csv, "\xEF\xBB\xBF");
fputcsv($csv, $cabecera, ';');
foreach ($filas as $f) {
  $archivoZip = '';
  if ($f['archivo']) {
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$f['archivo_tipo']] ?? 'bin';
    $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $f['fecha'] . '_' . $f['numero'] . '_' . $f['contraparte']));
    $archivoZip = substr(trim($base, '-'), 0, 90) . '.' . $ext;
    for ($n = 2; isset($nombresArchivo[$archivoZip]); $n++) $archivoZip = substr(trim($base, '-'), 0, 86) . "-$n.$ext";
    $nombresArchivo[$archivoZip] = $f['archivo'];
  }
  $fila = [evolvixCeldaFecha($f['fecha']), evolvixCeldaTexto($f['numero']), evolvixCeldaTexto($f['marca']),
    evolvixCeldaTexto($f['contraparte']), evolvixCeldaTexto($f['nif']), evolvixCeldaTexto($f['concepto'])];
  if (!$esEmitidas) $fila[] = evolvixCeldaTexto($f['categoria']);
  $estado = $f['estado'] === 'Pagada' ? ($esEmitidas ? 'Cobrada' : 'Pagada') : $f['estado'];
  $fila = array_merge($fila, [evolvixCeldaImporte($f['base']), evolvixCeldaImporte($f['iva_pct']), evolvixCeldaImporte($f['cuota_iva']),
    evolvixCeldaImporte($f['irpf_pct']), evolvixCeldaImporte($f['cuota_irpf']), evolvixCeldaImporte($f['total']),
    $estado, evolvixCeldaFecha($f['fecha_pago']), evolvixCeldaFecha($f['vencimiento']), $f['origen'], $archivoZip]);
  fputcsv($csv, $fila, ';');
}
rewind($csv);
$contenidoCsv = stream_get_contents($csv);
fclose($csv);

$periodo = $anio . ($trimestre ? "-T$trimestre" : '');
$nombre = "libro-facturas-$tipo-$periodo";

if ($formato === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header("Content-Disposition: attachment; filename=\"$nombre.csv\"");
  header('Cache-Control: private, no-store');
  echo $contenidoCsv;
  exit;
}

// --- ZIP ---------------------------------------------------------------------------
if (!class_exists('ZipArchive')) {
  evolvixJson(['ok' => false, 'mensaje' => 'El hosting no tiene activada la extensión ZIP de PHP. Descarga el CSV y los PDF por separado.'], 501);
}
$tmp = tempnam(sys_get_temp_dir(), 'evx');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$zip->addFromString("$nombre.csv", $contenidoCsv);
$dir = evolvixDirArchivos();
foreach ($nombresArchivo as $enZip => $interno) {
  $ruta = $dir . '/' . basename($interno);
  if (is_file($ruta)) $zip->addFile($ruta, "facturas/$enZip");
}
$zip->close();
header('Content-Type: application/zip');
header("Content-Disposition: attachment; filename=\"$nombre.zip\"");
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: private, no-store');
readfile($tmp);
@unlink($tmp);
