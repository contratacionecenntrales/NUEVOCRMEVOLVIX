<?php
require_once __DIR__ . '/config.php';

function evolvixConectar(): PDO {
  static $pdo = null;
  if ($pdo !== null) return $pdo;

  // Hostalia suele mostrar el servidor como «nombre:3306»; PDO quiere el
  // puerto aparte, así que se aceptan las dos formas.
  $host = trim(EVOLVIX_DB_HOST);
  $puerto = '';
  if (preg_match('/^(.+):(\d+)$/', $host, $m)) { $host = $m[1]; $puerto = ';port=' . $m[2]; }
  $dsn = 'mysql:host=' . $host . $puerto . ';dbname=' . trim(EVOLVIX_DB_NOMBRE) . ';charset=utf8mb4';
  try {
    $pdo = new PDO($dsn, EVOLVIX_DB_USUARIO, EVOLVIX_DB_CLAVE, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
  } catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mensaje' => 'No se ha podido conectar con la base de datos.',
      'detalle' => EVOLVIX_DEPURAR ? $e->getMessage() : null]);
    exit;
  }
  return $pdo;
}
