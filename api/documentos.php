<?php
// =============================================================================
// EVOLVIX GLOBAL · Facturas subidas (emitidas y recibidas)
//
//   GET                 → lista de todas (sin el archivo)
//   GET ?descargar=ID   → el PDF o la foto original (solo con sesión)
//   POST (multipart)    → sube un archivo; queda como BORRADOR por revisar
//   PATCH ?id=ID        → guarda los datos revisados, contabiliza o cambia
//                         el estado (cobrada/pagada, impagada, anulada)
//   DELETE ?id=ID       → descarta un BORRADOR. Una factura contabilizada
//                         nunca se borra, igual que en facturas.php: solo
//                         se anula y queda constancia de que existió.
//
// Los archivos se guardan con un nombre aleatorio y sin extensión en una
// carpeta que el .htaccess impide abrir desde fuera; solo salen por aquí,
// después de comprobar la sesión.
// =============================================================================

require_once __DIR__ . '/seguridad.php';

const EVOLVIX_TIPOS_ARCHIVO = [
  'application/pdf' => 'pdf',
  'image/jpeg' => 'jpg',
  'image/png' => 'png',
  'image/webp' => 'webp',
];
const EVOLVIX_MAX_BYTES = 20 * 1024 * 1024;

function evolvixDocumento(PDO $pdo, int $id): ?array {
  $stmt = $pdo->prepare('SELECT * FROM documentos WHERE id = ?');
  $stmt->execute([$id]);
  $d = $stmt->fetch();
  return $d ?: null;
}

// Lo que ve el panel: nunca el nombre interno del archivo en disco.
function evolvixDocumentoPublico(array $d): array {
  foreach (['id', 'marca_id', 'archivo_bytes'] as $k) $d[$k] = (int)$d[$k];
  foreach (['base', 'iva_pct', 'cuota_iva', 'irpf_pct', 'cuota_irpf', 'total'] as $k) $d[$k] = (float)$d[$k];
  $d['contabilizado'] = (bool)$d['contabilizado'];
  $d['tiene_archivo'] = !empty($d['archivo']);
  unset($d['archivo'], $d['archivo_sha256'], $d['creado_por'], $d['contabilizado_por']);
  return $d;
}

function evolvixEventoDocumento(PDO $pdo, int $id, string $tipo, string $detalle, string $uid): void {
  $pdo->prepare('INSERT INTO documento_eventos (documento_id, tipo, detalle, creado_por) VALUES (?,?,?,?)')
    ->execute([$id, $tipo, mb_substr($detalle, 0, 300), $uid]);
}

function evolvixFechaOVacio($v): ?string {
  $v = trim((string)$v);
  if ($v === '') return null;
  $d = DateTime::createFromFormat('!Y-m-d', $v);
  if (!$d || $d->format('Y-m-d') !== $v) evolvixJson(['ok' => false, 'mensaje' => "La fecha «{$v}» no es válida."], 400);
  return $v;
}

function evolvixImporte($v): float {
  if (!is_numeric($v)) return 0.0;
  $n = round((float)$v, 2);
  if (abs($n) > 9999999999) evolvixJson(['ok' => false, 'mensaje' => 'Importe fuera de rango.'], 400);
  return $n;
}

$metodo = $_SERVER['REQUEST_METHOD'];
$pdo = evolvixConectar();

// --- Descargar / ver el archivo original ------------------------------------
if ($metodo === 'GET' && isset($_GET['descargar'])) {
  evolvixRequiereSesion();
  $d = evolvixDocumento($pdo, (int)$_GET['descargar']);
  if (!$d || empty($d['archivo'])) evolvixJson(['ok' => false, 'mensaje' => 'Archivo no encontrado.'], 404);
  $ruta = evolvixDirArchivos() . '/' . basename($d['archivo']);
  if (!is_file($ruta)) evolvixJson(['ok' => false, 'mensaje' => 'El archivo ya no está en el servidor.'], 404);
  $tipo = array_key_exists($d['archivo_tipo'], EVOLVIX_TIPOS_ARCHIVO) ? $d['archivo_tipo'] : 'application/octet-stream';
  $nombre = preg_replace('/[^A-Za-z0-9._ -]/', '_', $d['archivo_nombre']) ?: ('factura.' . (EVOLVIX_TIPOS_ARCHIVO[$tipo] ?? 'bin'));
  header('Content-Type: ' . $tipo);
  header('Content-Length: ' . filesize($ruta));
  header('Content-Disposition: ' . (isset($_GET['bajar']) ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: private, no-store');
  readfile($ruta);
  exit;
}

// --- Listado ------------------------------------------------------------------
if ($metodo === 'GET') {
  evolvixRequiereSesion();
  $filas = $pdo->query('SELECT * FROM documentos ORDER BY COALESCE(fecha, DATE(creado)) DESC, id DESC')->fetchAll();
  evolvixJson(['ok' => true, 'datos' => array_map('evolvixDocumentoPublico', $filas)]);
}

// --- Subir un archivo ---------------------------------------------------------
if ($metodo === 'POST') {
  $u = evolvixRequiereSesion();

  // Si el archivo supera post_max_size, PHP vacía $_POST y $_FILES sin avisar.
  if (empty($_FILES['archivo'])) {
    evolvixJson(['ok' => false, 'mensaje' => 'El archivo no ha llegado. Puede que supere el límite de subida del hosting ('
      . ini_get('upload_max_filesize') . ').'], 400);
  }
  $f = $_FILES['archivo'];
  if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
    evolvixJson(['ok' => false, 'mensaje' => 'El archivo supera el límite de subida del hosting ('
      . ini_get('upload_max_filesize') . ').'], 413);
  }
  if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    evolvixJson(['ok' => false, 'mensaje' => 'No se ha podido recibir el archivo.'], 400);
  }
  if ($f['size'] > EVOLVIX_MAX_BYTES) {
    evolvixJson(['ok' => false, 'mensaje' => 'El archivo pesa más de 20 MB.'], 413);
  }

  // El tipo se decide por el contenido real del archivo, no por su nombre
  // ni por lo que diga el navegador.
  $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  if (!array_key_exists($tipo, EVOLVIX_TIPOS_ARCHIVO)) {
    evolvixJson(['ok' => false, 'mensaje' => 'Solo se admiten PDF o fotos (JPG, PNG, WEBP).'], 415);
  }

  $sha = hash_file('sha256', $f['tmp_name']);
  $dup = $pdo->prepare('SELECT id, numero, contraparte FROM documentos WHERE archivo_sha256 = ?');
  $dup->execute([$sha]);
  if ($ya = $dup->fetch()) {
    $quien = trim($ya['numero'] . ' ' . $ya['contraparte']);
    evolvixJson(['ok' => false, 'duplicado' => (int)$ya['id'],
      'mensaje' => 'Este archivo ya está subido' . ($quien !== '' ? " (factura {$quien})" : '') . '.'], 409);
  }

  $tipoDoc = ($_POST['tipo'] ?? '') === 'emitida' ? 'emitida' : 'recibida';
  $marcaId = (int)($_POST['marca_id'] ?? 0);
  if ($marcaId <= 0) {
    $marcaId = (int)$pdo->query('SELECT id FROM marcas WHERE activa = 1 ORDER BY id LIMIT 1')->fetchColumn();
  }
  $existe = $pdo->prepare('SELECT COUNT(*) FROM marcas WHERE id = ?');
  $existe->execute([$marcaId]);
  if (!(int)$existe->fetchColumn()) evolvixJson(['ok' => false, 'mensaje' => 'La marca indicada no existe.'], 400);

  $interno = bin2hex(random_bytes(16));
  $destino = evolvixDirArchivos() . '/' . $interno;
  if (!move_uploaded_file($f['tmp_name'], $destino)) {
    evolvixJson(['ok' => false, 'mensaje' => 'No se ha podido guardar el archivo en el servidor.'], 500);
  }
  @chmod($destino, 0640);

  $nombreOriginal = mb_substr(basename((string)$f['name']), 0, 255);
  try {
    $pdo->prepare(
      'INSERT INTO documentos (tipo, marca_id, archivo, archivo_nombre, archivo_tipo, archivo_bytes, archivo_sha256, creado_por)
       VALUES (?,?,?,?,?,?,?,?)'
    )->execute([$tipoDoc, $marcaId, $interno, $nombreOriginal, $tipo, (int)$f['size'], $sha, $u['id']]);
    $id = (int)$pdo->lastInsertId();
    evolvixEventoDocumento($pdo, $id, 'subido', "Subido «{$nombreOriginal}».", $u['id']);
  } catch (Throwable $e) {
    @unlink($destino);
    evolvixJson(['ok' => false, 'mensaje' => 'No se ha podido registrar el archivo.',
      'detalle' => EVOLVIX_DEPURAR ? $e->getMessage() : null], 500);
  }

  evolvixJson(['ok' => true, 'datos' => evolvixDocumentoPublico(evolvixDocumento($pdo, $id))]);
}

// --- Revisar, contabilizar, cambiar estado ------------------------------------
if ($metodo === 'PATCH') {
  $u = evolvixRequiereSesion();
  $id = (int)($_GET['id'] ?? 0);
  $d = $id > 0 ? evolvixDocumento($pdo, $id) : null;
  if (!$d) evolvixJson(['ok' => false, 'mensaje' => 'La factura no existe.'], 404);
  $c = evolvixCuerpo();

  // Reabrir una factura ya contabilizada (para corregir un dato) solo
  // puede hacerlo un administrador, y queda en el histórico.
  if (!empty($c['reabrir'])) {
    if ($u['rol'] !== 'admin') evolvixJson(['ok' => false, 'mensaje' => 'Solo un administrador puede reabrir una factura contabilizada.'], 403);
    if (!$d['contabilizado']) evolvixJson(['ok' => true]);
    $pdo->prepare('UPDATE documentos SET contabilizado = 0, contabilizado_por = NULL, contabilizado_en = NULL WHERE id = ?')->execute([$id]);
    evolvixEventoDocumento($pdo, $id, 'reabierta', 'Reabierta para corregir datos.', $u['id']);
    evolvixJson(['ok' => true, 'datos' => evolvixDocumentoPublico(evolvixDocumento($pdo, $id))]);
  }

  // Cambio de estado: cobrada/pagada, impagada, anulada o de vuelta a pendiente.
  if (array_key_exists('estado', $c)) {
    $estado = (string)$c['estado'];
    if (!in_array($estado, ['Pendiente', 'Pagada', 'Impagada', 'Anulada'], true)) {
      evolvixJson(['ok' => false, 'mensaje' => 'Estado no válido.'], 400);
    }
    if (!$d['contabilizado']) evolvixJson(['ok' => false, 'mensaje' => 'Contabiliza la factura antes de cambiar su estado.'], 409);
    if ($d['estado'] === 'Anulada') evolvixJson(['ok' => false, 'mensaje' => 'Una factura anulada ya no cambia de estado.'], 409);
    $fechaPago = $estado === 'Pagada' ? (evolvixFechaOVacio($c['fecha_pago'] ?? '') ?? date('Y-m-d')) : null;
    $pdo->prepare('UPDATE documentos SET estado = ?, fecha_pago = ? WHERE id = ?')->execute([$estado, $fechaPago, $id]);
    $texto = $estado === 'Pagada' ? ($d['tipo'] === 'emitida' ? 'Cobrada' : 'Pagada') : $estado;
    evolvixEventoDocumento($pdo, $id, 'estado:' . $estado, trim("{$d['numero']} pasó a {$texto}."), $u['id']);
    evolvixJson(['ok' => true, 'datos' => evolvixDocumentoPublico(evolvixDocumento($pdo, $id))]);
  }

  // Guardar los datos de un borrador (y, opcionalmente, contabilizarlo).
  if ($d['contabilizado']) {
    evolvixJson(['ok' => false, 'mensaje' => 'Esta factura ya está contabilizada. Para corregirla, un administrador tiene que reabrirla.'], 409);
  }

  $tipo = ($c['tipo'] ?? $d['tipo']) === 'emitida' ? 'emitida' : 'recibida';
  $marcaId = (int)($c['marca_id'] ?? $d['marca_id']);
  $existe = $pdo->prepare('SELECT COUNT(*) FROM marcas WHERE id = ?');
  $existe->execute([$marcaId]);
  if (!(int)$existe->fetchColumn()) evolvixJson(['ok' => false, 'mensaje' => 'La marca indicada no existe.'], 400);

  $txt = function (string $k, int $max) use ($c, $d) {
    return mb_substr(trim((string)($c[$k] ?? $d[$k])), 0, $max);
  };
  $numero = $txt('numero', 60);
  $contraparte = $txt('contraparte', 200);
  $nif = strtoupper(preg_replace('/[\s.\-]/', '', $txt('nif', 40)));
  $concepto = $txt('concepto', 300);
  $categoria = $txt('categoria', 60);
  $notas = $txt('notas', 500);
  $fecha = evolvixFechaOVacio($c['fecha'] ?? $d['fecha']);
  $vencimiento = evolvixFechaOVacio($c['vencimiento'] ?? $d['vencimiento']);
  $base = evolvixImporte($c['base'] ?? $d['base']);
  $ivaPct = evolvixImporte($c['iva_pct'] ?? $d['iva_pct']);
  $cuotaIva = evolvixImporte($c['cuota_iva'] ?? $d['cuota_iva']);
  $irpfPct = evolvixImporte($c['irpf_pct'] ?? $d['irpf_pct']);
  $cuotaIrpf = evolvixImporte($c['cuota_irpf'] ?? $d['cuota_irpf']);
  // El total siempre lo calcula el servidor: así lo que se contabiliza
  // cuadra por construcción con la base y las cuotas.
  $total = round($base + $cuotaIva - $cuotaIrpf, 2);

  $contabilizar = !empty($c['contabilizar']);
  if ($contabilizar) {
    $faltan = [];
    if ($numero === '') $faltan[] = 'número';
    if ($fecha === null) $faltan[] = 'fecha';
    if ($contraparte === '') $faltan[] = $tipo === 'emitida' ? 'cliente' : 'proveedor';
    if ($base == 0 && $total == 0) $faltan[] = 'importes';
    if ($faltan) evolvixJson(['ok' => false, 'mensaje' => 'Para contabilizar falta: ' . implode(', ', $faltan) . '.'], 400);

    // La misma factura (mismo número del mismo emisor/cliente) no puede
    // entrar dos veces en los libros, aunque se haya subido otro archivo.
    $dup = $pdo->prepare(
      "SELECT id FROM documentos WHERE contabilizado = 1 AND tipo = ? AND numero = ? AND nif = ? AND id <> ? AND estado <> 'Anulada'"
    );
    $dup->execute([$tipo, $numero, $nif, $id]);
    if ($otra = $dup->fetchColumn()) {
      evolvixJson(['ok' => false, 'mensaje' => "La factura {$numero}" . ($nif ? " de {$nif}" : '') . " ya está contabilizada (registro #{$otra})."], 409);
    }
  }

  $pdo->prepare(
    'UPDATE documentos SET tipo=?, marca_id=?, numero=?, fecha=?, vencimiento=?, contraparte=?, nif=?, concepto=?, categoria=?,
       base=?, iva_pct=?, cuota_iva=?, irpf_pct=?, cuota_irpf=?, total=?, notas=?'
    . ($contabilizar ? ', contabilizado = 1, contabilizado_por = ?, contabilizado_en = NOW()' : '')
    . ' WHERE id = ?'
  )->execute(array_merge(
    [$tipo, $marcaId, $numero, $fecha, $vencimiento, $contraparte, $nif, $concepto, $categoria,
      $base, $ivaPct, $cuotaIva, $irpfPct, $cuotaIrpf, $total, $notas],
    $contabilizar ? [$u['id']] : [],
    [$id]
  ));
  if ($contabilizar) {
    evolvixEventoDocumento($pdo, $id, 'contabilizada', "Factura {$numero} contabilizada por " . number_format($total, 2, ',', '.') . ' €.', $u['id']);
  }
  evolvixJson(['ok' => true, 'datos' => evolvixDocumentoPublico(evolvixDocumento($pdo, $id))]);
}

// --- Descartar un borrador ----------------------------------------------------
if ($metodo === 'DELETE') {
  $u = evolvixRequiereSesion();
  $id = (int)($_GET['id'] ?? 0);
  $d = $id > 0 ? evolvixDocumento($pdo, $id) : null;
  if (!$d) evolvixJson(['ok' => false, 'mensaje' => 'La factura no existe.'], 404);
  if ($d['contabilizado']) {
    evolvixJson(['ok' => false, 'mensaje' => 'Una factura contabilizada no se puede borrar; solo anular.'], 409);
  }
  $pdo->prepare('DELETE FROM documentos WHERE id = ? AND contabilizado = 0')->execute([$id]);
  if (!empty($d['archivo'])) @unlink(evolvixDirArchivos() . '/' . basename($d['archivo']));
  evolvixEventoDocumento($pdo, $id, 'descartado', "Borrador «{$d['archivo_nombre']}» descartado.", $u['id']);
  evolvixJson(['ok' => true]);
}

evolvixJson(['ok' => false, 'mensaje' => 'Método no permitido.'], 405);
