<?php
session_start();
require_once __DIR__ . "/db.php";

$USUARIOS = [
    "ADMIN" => "Uma334484",
    "AGUERO" => "Beto2026",
    "MONTELEONE" => "Claudio2026",
    "ESCUDERO" => "Vero2026"
];

$dataDir = __DIR__ . "/data";
$uploadDir = __DIR__ . "/comprobantes";

$empresasFile = $dataDir . "/empresas.json";
$pagosFile = $dataDir . "/pagos.json";
$auditoriaFile = $dataDir . "/auditoria.json";
$papeleraPagosFile = $dataDir . "/papelera_pagos.json";

if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

function e($v) {
    return htmlspecialchars($v ?? "", ENT_QUOTES, "UTF-8");
}

function pdoSistema() {
    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        die("No se pudo conectar a la base de datos.");
    }
    return $pdo;
}

function columnasTabla($tabla) {
    static $cache = [];
    $tablasPermitidas = ["empresas", "pagos", "auditoria", "papelera_pagos"];
    if (!in_array($tabla, $tablasPermitidas, true)) {
        throw new RuntimeException("Tabla no permitida.");
    }
    if (!isset($cache[$tabla])) {
        $stmt = pdoSistema()->query("SHOW COLUMNS FROM `$tabla`");
        $cache[$tabla] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $cache[$tabla];
}

function nombresColumnasTabla($tabla) {
    return array_map(fn($columna) => $columna["Field"], columnasTabla($tabla));
}

function columnaTabla($tabla, $nombre) {
    foreach (columnasTabla($tabla) as $columna) {
        if (($columna["Field"] ?? "") === $nombre) return $columna;
    }
    return null;
}

function columnaAutoIncremental($columna) {
    return isset($columna["Extra"]) && stripos($columna["Extra"], "auto_increment") !== false;
}

function columnaNumerica($columna) {
    return isset($columna["Type"]) && preg_match('/\b(int|decimal|float|double|real|numeric)\b/i', $columna["Type"]);
}

function columnaTexto($columna) {
    return isset($columna["Type"]) && preg_match('/\b(char|text|json)\b/i', $columna["Type"]);
}

function decodificarValorBD($valor) {
    if (!is_string($valor)) return $valor;
    $trim = trim($valor);
    if ($trim === "") return $valor;
    if ($trim[0] !== "{" && $trim[0] !== "[") return $valor;
    $decodificado = json_decode($trim, true);
    return json_last_error() === JSON_ERROR_NONE ? $decodificado : $valor;
}

function normalizarRegistroDesdeBD($tabla, $fila) {
    $registro = [];
    if (isset($fila["json_id"]) && trim((string)$fila["json_id"]) !== "") {
        $registro["id"] = $fila["json_id"];
    }
    $columnaJson = null;
    foreach (["data", "datos", "json", "payload"] as $posible) {
        if (array_key_exists($posible, $fila)) {
            $columnaJson = $posible;
            break;
        }
    }

    if ($columnaJson !== null) {
        $datos = decodificarValorBD($fila[$columnaJson]);
        if (is_array($datos)) $registro = $datos;
    }

    foreach ($fila as $campo => $valor) {
        if ($campo === $columnaJson) continue;
        if ($campo === "json_id") continue;
        if ($campo === "empresa_json_id") {
            $registro["empresa_id"] = decodificarValorBD($valor);
            continue;
        }
        $columna = columnaTabla($tabla, $campo);
        if ($campo === "id" && columnaNumerica($columna) && !columnaTexto($columna)) continue;
        $registro[$campo] = decodificarValorBD($valor);
    }

    if (!isset($registro["id"])) {
        foreach (["pago_id", "empresa_id_original", "registro_id"] as $campoId) {
            if (isset($fila[$campoId]) && trim((string)$fila[$campoId]) !== "") {
                $registro["id"] = $fila[$campoId];
                break;
            }
        }
    }

    if (array_key_exists("activa", $registro) && ($registro["activa"] === null || $registro["activa"] === "")) {
        unset($registro["activa"]);
    } elseif (array_key_exists("activa", $registro)) {
        $registro["activa"] = filter_var($registro["activa"], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($registro["activa"] === null) $registro["activa"] = false;
    }

    return $registro;
}

function columnaOrdenTabla($tabla) {
    $columnas = nombresColumnasTabla($tabla);
    foreach (["fecha_carga", "fecha", "created_at", "id"] as $columna) {
        if (in_array($columna, $columnas, true)) return $columna;
    }
    return null;
}

function obtenerRegistros($tabla) {
    $orden = columnaOrdenTabla($tabla);
    $sql = "SELECT * FROM `$tabla`" . ($orden ? " ORDER BY `$orden` ASC" : "");
    $stmt = pdoSistema()->query($sql);
    return array_map(fn($fila) => normalizarRegistroDesdeBD($tabla, $fila), $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function valorParaBD($valor) {
    if (is_array($valor) || is_object($valor)) {
        return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (is_bool($valor)) return $valor ? 1 : 0;
    return $valor;
}

function datosInsertables($tabla, $registro) {
    $columnas = columnasTabla($tabla);
    $nombres = array_column($columnas, "Field");
    $datos = [];
    $columnaJson = null;
    foreach (["data", "datos", "json", "payload"] as $posible) {
        if (in_array($posible, $nombres, true)) {
            $columnaJson = $posible;
            break;
        }
    }

    if ($columnaJson !== null) {
        $datos[$columnaJson] = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    foreach ($columnas as $columna) {
        $campo = $columna["Field"];
        if ($campo === $columnaJson) continue;
        if ($campo === "json_id" && isset($registro["id"])) {
            $datos[$campo] = $registro["id"];
            continue;
        }
        if ($campo === "empresa_json_id" && isset($registro["empresa_id"])) {
            $datos[$campo] = $registro["empresa_id"];
            continue;
        }
        if (array_key_exists($campo, $registro)) {
            if ($campo === "id" && columnaNumerica($columna) && !is_numeric($registro[$campo])) continue;
            $datos[$campo] = valorParaBD($registro[$campo]);
        }
    }

    if (isset($registro["id"]) && !isset($datos["id"]) && in_array("pago_id", $nombres, true)) {
        $datos["pago_id"] = $registro["id"];
    }

    foreach ($columnas as $columna) {
        $campo = $columna["Field"];
        if (isset($datos[$campo]) || columnaAutoIncremental($columna)) continue;
        if (($columna["Null"] ?? "") === "NO" && ($columna["Default"] ?? null) === null) {
            if (columnaNumerica($columna)) {
                $datos[$campo] = 0;
            } elseif (preg_match('/\b(date|datetime|timestamp)\b/i', $columna["Type"] ?? "")) {
                $tipoColumna = strtolower($columna["Type"] ?? "");
                $datos[$campo] = preg_match('/^date\b/', $tipoColumna) ? date("Y-m-d") : date("Y-m-d H:i:s");
            } elseif (stripos($columna["Type"] ?? "", "json") !== false) {
                $datos[$campo] = "[]";
            } else {
                $datos[$campo] = "";
            }
        }
    }

    return $datos;
}

function columnaClaveLogica($tabla) {
    $nombres = nombresColumnasTabla($tabla);
    if (in_array("json_id", $nombres, true)) return "json_id";

    $columnaId = columnaTabla($tabla, "id");
    if ($columnaId && !columnaAutoIncremental($columnaId) && (!columnaNumerica($columnaId) || columnaTexto($columnaId))) {
        return "id";
    }

    foreach (["pago_id", "empresa_id_original", "registro_id"] as $campo) {
        if (in_array($campo, $nombres, true)) return $campo;
    }

    return null;
}

function guardarRegistros($tabla, $registros) {
    $pdo = pdoSistema();
    $registros = is_array($registros) ? array_values($registros) : [];
    $columnaClave = columnaClaveLogica($tabla);
    $todosConClave = $columnaClave && count(array_filter($registros, fn($registro) => trim((string)($registro["id"] ?? "")) !== "")) === count($registros);

    $pdo->beginTransaction();
    try {
        if (!$todosConClave) {
            $pdo->exec("DELETE FROM `$tabla`");
        }

        $clavesActuales = [];
        foreach ($registros as $registro) {
            $datos = datosInsertables($tabla, $registro);
            if (!$datos) continue;

            $campos = array_keys($datos);
            $params = [];
            foreach ($datos as $campo => $valor) {
                $params[":$campo"] = $valor;
            }

            if ($todosConClave) {
                $clave = (string)$registro["id"];
                $clavesActuales[] = $clave;
                $datos[$columnaClave] = $clave;
                $campos = array_keys($datos);
                $existeStmt = $pdo->prepare("SELECT COUNT(*) FROM `$tabla` WHERE `$columnaClave` = ?");
                $existeStmt->execute([$clave]);
                $existe = intval($existeStmt->fetchColumn()) > 0;

                if ($existe) {
                    $actualizaciones = array_values(array_filter($campos, fn($campo) => $campo !== $columnaClave && !columnaAutoIncremental(columnaTabla($tabla, $campo))));
                    if ($actualizaciones) {
                        $setSql = implode(", ", array_map(fn($campo) => "`$campo` = :$campo", $actualizaciones));
                        $updateParams = [];
                        foreach ($actualizaciones as $campo) {
                            $updateParams[":$campo"] = $datos[$campo];
                        }
                        $updateParams[":clave_logica"] = $clave;
                        $stmt = $pdo->prepare("UPDATE `$tabla` SET $setSql WHERE `$columnaClave` = :clave_logica");
                        $stmt->execute($updateParams);
                    }
                    continue;
                }
            } else {
                $datos = array_filter(
                    $datos,
                    fn($valor, $campo) => !columnaAutoIncremental(columnaTabla($tabla, $campo)),
                    ARRAY_FILTER_USE_BOTH
                );
                $campos = array_keys($datos);
            }

            $columnasSql = implode(", ", array_map(fn($campo) => "`$campo`", $campos));
            $marcasSql = implode(", ", array_map(fn($campo) => ":$campo", $campos));
            $params = [];
            foreach ($datos as $campo => $valor) {
                $params[":$campo"] = $valor;
            }
            $stmt = $pdo->prepare("INSERT INTO `$tabla` ($columnasSql) VALUES ($marcasSql)");
            $stmt->execute($params);
        }

        if ($todosConClave) {
            if ($clavesActuales) {
                $marcas = implode(", ", array_fill(0, count($clavesActuales), "?"));
                $stmt = $pdo->prepare("DELETE FROM `$tabla` WHERE `$columnaClave` NOT IN ($marcas)");
                $stmt->execute($clavesActuales);
            } else {
                $pdo->exec("DELETE FROM `$tabla`");
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function obtenerEmpresas() { return obtenerRegistros("empresas"); }
function guardarEmpresas($empresas) { guardarRegistros("empresas", $empresas); }
function obtenerPagos() { return obtenerRegistros("pagos"); }
function guardarPagos($pagos) { guardarRegistros("pagos", $pagos); }
function obtenerAuditoria() { return obtenerRegistros("auditoria"); }
function guardarAuditoria($auditoria) { guardarRegistros("auditoria", $auditoria); }
function obtenerPapeleraPagos() { return obtenerRegistros("papelera_pagos"); }
function guardarPapeleraPagos($papeleraPagos) { guardarRegistros("papelera_pagos", $papeleraPagos); }

function usuarioActual() {
    return $_SESSION["usuario"] ?? $_SESSION["usuario_logueado"] ?? "";
}

function usuarioEsAdmin() {
    return usuarioActual() === "ADMIN";
}

function accesoDenegado() {
    http_response_code(403);
    echo "Acceso denegado";
    exit;
}

function requerirAdmin() {
    if (!usuarioEsAdmin()) {
        accesoDenegado();
    }
}

function empresaActiva($empresa) {
    return !array_key_exists("activa", $empresa) || $empresa["activa"] !== false;
}

function registrarAuditoria($auditoriaFile, $accion, $detalle) {
    $auditoria = obtenerAuditoria();
    $auditoria[] = [
        "fecha" => date("Y-m-d H:i:s"),
        "usuario" => usuarioActual() ?: "SISTEMA",
        "accion" => $accion,
        "detalle" => $detalle
    ];
    guardarAuditoria($auditoria);
}

function detalleEmpresa($empresa) {
    $razon = trim($empresa["razon"] ?? "Empresa");
    $cuit = trim($empresa["cuit"] ?? "");
    return $razon . ($cuit !== "" ? " - CUIT " . $cuit : "");
}

function detallePago($pago, $empresas) {
    $empresa = buscarEmpresa($empresas, $pago["empresa_id"] ?? "");
    return ($empresa["razon"] ?? "Empresa eliminada")
        . " - " . ($pago["tipo"] ?? "")
        . " - " . periodoParaInput($pago["periodo"] ?? "")
        . " - " . dinero($pago["monto"] ?? 0);
}

function fechaChequeValida($fecha) {
    $fecha = trim($fecha ?? "");
    $objeto = DateTime::createFromFormat("!Y-m-d", $fecha);
    return $objeto && $objeto->format("Y-m-d") === $fecha;
}

function chequesPago($pago) {
    return isset($pago["cheques"]) && is_array($pago["cheques"]) ? $pago["cheques"] : [];
}

function estadoCheque($cheque, $hoy = null) {
    if (!empty($cheque["cobrado"])) return "Cobrado";
    $fecha = trim($cheque["fecha_cobro"] ?? "");
    $hoy = $hoy ?: date("Y-m-d");
    if ($fecha === $hoy) return "Vence hoy";
    if ($fecha !== "" && $fecha < $hoy) return "Vencido";
    return "Pendiente";
}

function fechasCheques($cheques) {
    return array_values(array_map(
        fn($cheque) => trim($cheque["fecha_cobro"] ?? ""),
        is_array($cheques) ? $cheques : []
    ));
}

function fechaParaMostrar($fecha) {
    if (!fechaChequeValida($fecha)) return trim($fecha ?? "");
    return DateTime::createFromFormat("!Y-m-d", $fecha)->format("d/m/Y");
}

function enviarCsv($nombreArchivo, $columnas, $filas) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"" . $nombreArchivo . "\"");
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");

    $salida = fopen("php://output", "w");
    fwrite($salida, "\xEF\xBB\xBF");
    fputcsv($salida, $columnas, ";");
    foreach ($filas as $fila) {
        fputcsv($salida, $fila, ";");
    }
    fclose($salida);
    exit;
}

function dinero($n) {
    return "$" . number_format(floatval($n), 2, ",", ".");
}

function limpiarArchivo($txt) {
    return trim(preg_replace('/[^A-Za-z0-9_\-]/', '_', $txt), "_");
}

function periodoParaInput($periodo) {
    $periodo = trim($periodo ?? "");
    if (preg_match('/^\d{4}-\d{2}$/', $periodo)) {
        return substr($periodo, 5, 2) . "/" . substr($periodo, 2, 2);
    }
    if (preg_match('/^\d{2}\/\d{2}$/', $periodo)) {
        return $periodo;
    }
    if (preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $periodo)) {
        return substr($periodo, 3, 5);
    }
    return $periodo;
}

function periodoValido($periodo) {
    $periodo = trim($periodo ?? "");
    return preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $periodo) === 1;
}

function periodoAIndice($periodo) {
    if (!periodoValido($periodo)) return null;
    [$mes, $anio] = array_map('intval', explode('/', $periodo));
    return (2000 + $anio) * 12 + $mes;
}

function rutaComprobanteFisico($comprobante, $uploadDir) {
    $comprobante = trim($comprobante ?? "");
    if ($comprobante === "") return null;

    $normalizado = str_replace("\\", "/", $comprobante);
    if (substr($normalizado, 0, 13) !== "comprobantes/") return null;

    $archivo = basename($normalizado);
    if ($archivo === "") return null;

    return $uploadDir . "/" . $archivo;
}

function buscarEmpresa($empresas, $id) {
    foreach ($empresas as $e) {
        if (($e["id"] ?? "") === $id) return $e;
    }
    return null;
}

function formaSocietariaEmpresa($razon) {
    $texto = strtoupper(strtr(trim($razon ?? ""), [
        "Á" => "A", "É" => "E", "Í" => "I", "Ó" => "O", "Ú" => "U", "Ü" => "U", "Ñ" => "N",
        "á" => "A", "é" => "E", "í" => "I", "ó" => "O", "ú" => "U", "ü" => "U", "ñ" => "N"
    ]));
    if (preg_match('/(?:^|[^A-Z0-9])S\s*\.?\s*A\s*\.?\s*S\s*\.?\s*$/', $texto)) return "SAS";
    if (preg_match('/(?:^|[^A-Z0-9])S\s*\.?\s*R\s*\.?\s*L\s*\.?\s*$/', $texto)) return "SRL";
    if (preg_match('/(?:^|[^A-Z0-9])S\s*\.?\s*A\s*\.?\s*$/', $texto)) return "SA";
    return "";
}

function normalizarRazonSocial($razon) {
    $texto = strtr(trim($razon ?? ""), [
        "Á" => "A", "É" => "E", "Í" => "I", "Ó" => "O", "Ú" => "U", "Ü" => "U", "Ñ" => "N",
        "á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n"
    ]);
    $texto = strtolower($texto);
    $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto);
    $texto = preg_replace('/\b(?:s\s*a\s*s|s\s*r\s*l|s\s*a|sas|srl|sa)\b/', ' ', $texto);
    return trim(preg_replace('/\s+/', ' ', $texto));
}

function claveExactaRazonSocial($razon) {
    return normalizarRazonSocial($razon) . "|" . formaSocietariaEmpresa($razon);
}

function normalizarCuit($cuit) {
    return preg_replace('/\D+/', '', trim($cuit ?? ""));
}

function palabrasImportantesRazon($razon) {
    return array_values(array_unique(array_filter(
        explode(" ", normalizarRazonSocial($razon)),
        fn($palabra) => strlen($palabra) >= 3
    )));
}

function razonesParecidas($razonA, $razonB) {
    $a = normalizarRazonSocial($razonA);
    $b = normalizarRazonSocial($razonB);
    if ($a === "" || $b === "") return false;
    if ($a === $b) return true;
    if (strpos($a, $b) !== false || strpos($b, $a) !== false) return true;

    return count(array_intersect(palabrasImportantesRazon($a), palabrasImportantesRazon($b))) >= 2;
}

function buscarCoincidenciasEmpresa($empresas, $razon, $cuit, $idIgnorado = "") {
    $resultado = ["cuit" => null, "exacta" => null, "parecidas" => []];
    $cuitNormalizado = normalizarCuit($cuit);
    $claveExacta = claveExactaRazonSocial($razon);

    foreach ($empresas as $empresa) {
        if ($idIgnorado !== "" && ($empresa["id"] ?? "") === $idIgnorado) continue;

        if (
            !$resultado["cuit"] &&
            $cuitNormalizado !== "" &&
            normalizarCuit($empresa["cuit"] ?? "") === $cuitNormalizado
        ) {
            $resultado["cuit"] = $empresa;
        }

        if (
            !$resultado["exacta"] &&
            normalizarRazonSocial($empresa["razon"] ?? "") !== "" &&
            claveExactaRazonSocial($empresa["razon"] ?? "") === $claveExacta
        ) {
            $resultado["exacta"] = $empresa;
        } elseif (razonesParecidas($razon, $empresa["razon"] ?? "")) {
            $resultado["parecidas"][] = $empresa;
        }
    }

    return $resultado;
}

function existePagoEmpresaTipoPeriodoTipoPago($pagos, $empresas, $empresaId, $tipo, $periodo, $tipoPago, $pagoIdIgnorado = "") {
    $periodoNormalizado = periodoParaInput($periodo);
    foreach ($pagos as $pago) {
        if ($pagoIdIgnorado !== "" && ($pago["id"] ?? "") === $pagoIdIgnorado) continue;
        if (
            ($pago["empresa_id"] ?? "") === $empresaId &&
            ($pago["tipo"] ?? "") === $tipo &&
            periodoParaInput($pago["periodo"] ?? "") === $periodoNormalizado &&
            tipoPagoCompatible($pago, $empresas) === $tipoPago
        ) {
            return true;
        }
    }
    return false;
}

function totalPagado($pagos, $empresaId, $tipo) {
    $total = 0;
    foreach ($pagos as $p) {
        if (($p["empresa_id"] ?? "") === $empresaId && ($p["tipo"] ?? "") === $tipo) {
            $total += floatval($p["monto"] ?? 0);
        }
    }
    return $total;
}

function pagosPreviosIdsAcuerdo($acuerdo) {
    $ids = $acuerdo["pagos_previos_ids"] ?? [];
    if (!is_array($ids)) return [];
    return array_values(array_unique(array_filter(array_map("strval", $ids), fn($id) => $id !== "")));
}

function pagosPreviosVinculados($acuerdo, $pagos, $empresaId, $tipo) {
    $ids = array_flip(pagosPreviosIdsAcuerdo($acuerdo));
    if (!$ids) return [];
    return array_values(array_filter($pagos, fn($pago) =>
        isset($ids[(string)($pago["id"] ?? "")]) &&
        ($pago["empresa_id"] ?? "") === $empresaId &&
        ($pago["tipo"] ?? "") === $tipo
    ));
}

function pagoVinculadoComoPrevio($pago, $empresa) {
    $tipo = $pago["tipo"] ?? "";
    if ($tipo === "") return false;
    return in_array((string)($pago["id"] ?? ""), pagosPreviosIdsAcuerdo(acuerdoEmpresa($empresa, $tipo)), true);
}

function resumenFinancieroEmpresaTipo($empresa, $tipo, $pagos) {
    $tieneAcuerdo = acuerdoValidoEmpresaTipo($empresa, $tipo);
    $acuerdo = acuerdoEmpresa($empresa, $tipo);
    $pagosRegistrados = totalPagado($pagos, $empresa["id"] ?? "", $tipo);
    $montoCuota = $tieneAcuerdo ? max(floatval($acuerdo["monto_cuota"] ?? 0), 0) : 0;
    $cuotasPrevias = $tieneAcuerdo ? max(intval($acuerdo["cuotas_pagadas_previas"] ?? 0), 0) : 0;
    $pagosPrevios = $tieneAcuerdo ? pagosPreviosVinculados($acuerdo, $pagos, $empresa["id"] ?? "", $tipo) : [];
    $cuotasPreviasSinRegistro = max($cuotasPrevias - count($pagosPrevios), 0);
    $deuda = $tieneAcuerdo ? max(floatval($acuerdo["monto_total"] ?? 0), 0) : 0;
    $cobrado = $pagosRegistrados + ($cuotasPreviasSinRegistro * $montoCuota);

    return [
        "tiene_acuerdo" => $tieneAcuerdo,
        "deuda" => $deuda,
        "cobrado" => $cobrado,
        "saldo" => max($deuda - $cobrado, 0),
        "pagos_previos_vinculados" => count($pagosPrevios),
        "cuotas_previas_sin_registro" => $cuotasPreviasSinRegistro
    ];
}

function eliminarComprobantePago($pago, $uploadDir) {
    $rutaFisica = rutaComprobanteFisico($pago["comprobante"] ?? "", $uploadDir);
    if ($rutaFisica && is_file($rutaFisica)) {
        @unlink($rutaFisica);
    }
}

function agregarDirectorioZip($zip, $directorio, $nombreEnZip) {
    if (!is_dir($directorio)) return true;

    $nombreEnZip = trim(str_replace("\\", "/", $nombreEnZip), "/");
    if ($nombreEnZip !== "") {
        $zip->addEmptyDir($nombreEnZip);
    }

    $archivos = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directorio, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($archivos as $archivo) {
        $ruta = $archivo->getPathname();
        $relativa = substr($ruta, strlen($directorio) + 1);
        $rutaZip = $nombreEnZip . "/" . str_replace("\\", "/", $relativa);

        if ($archivo->isDir()) {
            $zip->addEmptyDir($rutaZip);
        } elseif (!$zip->addFile($ruta, $rutaZip)) {
            return false;
        }
    }

    return true;
}

function enviarBackupManual($empresasFile, $pagosFile, $auditoriaFile, $papeleraPagosFile, $uploadDir) {
    if (!class_exists("ZipArchive")) return false;

    $nombreDescarga = "backup_" . date("Y-m-d_H-i") . ".zip";
    $zipTemporal = tempnam(sys_get_temp_dir(), "backup_pagos_");
    if ($zipTemporal === false) return false;

    $zip = new ZipArchive();
    if ($zip->open($zipTemporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($zipTemporal);
        return false;
    }

    $ok = true;
    $jsonTemporales = [];
    $datosBackup = [
        "empresas.json" => obtenerEmpresas(),
        "pagos.json" => obtenerPagos(),
        "auditoria.json" => obtenerAuditoria(),
        "papelera_pagos.json" => obtenerPapeleraPagos()
    ];
    foreach ($datosBackup as $nombreJson => $datosJson) {
        $archivoJson = tempnam(sys_get_temp_dir(), "backup_json_");
        if ($archivoJson === false) {
            $ok = false;
            break;
        }
        $jsonTemporales[] = $archivoJson;
        $ok = $ok && file_put_contents($archivoJson, json_encode($datosJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
        $ok = $ok && $zip->addFile($archivoJson, $nombreJson);
    }
    $ok = $ok && agregarDirectorioZip($zip, $uploadDir, "comprobantes");

    if (!$zip->close() || !$ok) {
        foreach ($jsonTemporales as $archivoJson) {
            @unlink($archivoJson);
        }
        @unlink($zipTemporal);
        return false;
    }
    foreach ($jsonTemporales as $archivoJson) {
        @unlink($archivoJson);
    }

    if (!is_file($zipTemporal)) {
        @unlink($zipTemporal);
        return false;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    ignore_user_abort(true);
    register_shutdown_function(function($archivo) {
        if (is_file($archivo)) {
            @unlink($archivo);
        }
    }, $zipTemporal);

    header("Content-Type: application/zip");
    header("Content-Disposition: attachment; filename=\"" . $nombreDescarga . "\"");
    header("Content-Length: " . filesize($zipTemporal));
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    readfile($zipTemporal);
    @unlink($zipTemporal);
    exit;
}

function acuerdoDefault() {
    return [
        "monto_total" => 0,
        "cantidad_cuotas" => 1,
        "monto_cuota" => 0,
        "cuotas_pagadas_previas" => 0,
        "pagos_previos_ids" => [],
        "periodo_desde" => "",
        "periodo_hasta" => "",
        "observaciones" => ""
    ];
}

function acuerdoEmpresa($empresa, $tipo) {
    $base = acuerdoDefault();
    if (isset($empresa["acuerdos"]) && is_array($empresa["acuerdos"]) && isset($empresa["acuerdos"][$tipo]) && is_array($empresa["acuerdos"][$tipo])) {
        return array_merge($base, $empresa["acuerdos"][$tipo]);
    }

    if (!empty($empresa["monto_total"]) || !empty($empresa["monto_cuota"]) || !empty($empresa["periodo_desde"]) || !empty($empresa["periodo_hasta"])) {
        return array_merge($base, [
            "monto_total" => floatval($empresa["monto_total"] ?? 0),
            "cantidad_cuotas" => max(intval($empresa["cantidad_cuotas"] ?? 1), 1),
            "monto_cuota" => floatval($empresa["monto_cuota"] ?? 0),
            "cuotas_pagadas_previas" => max(intval($empresa["cuotas_pagadas_previas"] ?? 0), 0),
            "periodo_desde" => periodoParaInput($empresa["periodo_desde"] ?? ""),
            "periodo_hasta" => periodoParaInput($empresa["periodo_hasta"] ?? ""),
            "observaciones" => $empresa["observaciones_acuerdo"] ?? ""
        ]);
    }

    return $base;
}

function acuerdoValidoEmpresaTipo($empresa, $tipo) {
    $acuerdo = acuerdoEmpresa($empresa, $tipo);
    return intval($acuerdo["cantidad_cuotas"] ?? 0) >= 2
        && floatval($acuerdo["monto_total"] ?? 0) > 0
        && floatval($acuerdo["monto_cuota"] ?? 0) > 0
        && periodoValido(periodoParaInput($acuerdo["periodo_desde"] ?? ""))
        && periodoValido(periodoParaInput($acuerdo["periodo_hasta"] ?? ""));
}

function periodoPerteneceAcuerdo($acuerdo, $periodo) {
    $consultado = periodoAIndice(periodoParaInput($periodo));
    $desde = periodoAIndice(periodoParaInput($acuerdo["periodo_desde"] ?? ""));
    $hasta = periodoAIndice(periodoParaInput($acuerdo["periodo_hasta"] ?? ""));
    return $consultado !== null && $desde !== null && $hasta !== null
        && $consultado >= $desde && $consultado <= $hasta;
}

function periodoEsCuotaPrevia($acuerdo, $periodo) {
    $consultado = periodoAIndice(periodoParaInput($periodo));
    $desde = periodoAIndice(periodoParaInput($acuerdo["periodo_desde"] ?? ""));
    $previas = max(intval($acuerdo["cuotas_pagadas_previas"] ?? 0), 0);
    if ($consultado === null || $desde === null) return false;
    $numeroCuota = $consultado - $desde + 1;
    return $numeroCuota >= 1 && $numeroCuota <= $previas;
}

function tipoPagoCompatible($pago, $empresas) {
    if (in_array($pago["tipo_pago"] ?? "", ["Pago al día", "Cuota de acuerdo", "Pago único / extraordinario"], true)) {
        return $pago["tipo_pago"];
    }
    if (($pago["tipo_pago"] ?? "") === "Pago único") {
        return "Pago único / extraordinario";
    }
    if (($pago["pago_tipo"] ?? "") === "Cuotas") {
        return "Cuota de acuerdo";
    }
    if (($pago["pago_tipo"] ?? "") === "Pago total") {
        return "Pago único / extraordinario";
    }
    $empresa = buscarEmpresa($empresas, $pago["empresa_id"] ?? "");
    if ($empresa && acuerdoValidoEmpresaTipo($empresa, $pago["tipo"] ?? "")) {
        $acuerdo = acuerdoEmpresa($empresa, $pago["tipo"] ?? "");
        if (periodoPerteneceAcuerdo($acuerdo, $pago["periodo"] ?? "")) {
            return "Cuota de acuerdo";
        }
    }
    return "Pago único / extraordinario";
}

function resumenAcuerdosEmpresa($empresa) {
    $partes = [];
    foreach (["Obra Social","Sindicato","Mutual"] as $tipo) {
        $a = acuerdoEmpresa($empresa, $tipo);
        $cuotas = intval($a["cantidad_cuotas"] ?? 0);
        if ($cuotas < 2 || floatval($a["monto_total"] ?? 0) <= 0 || floatval($a["monto_cuota"] ?? 0) <= 0) continue;

        $desde = periodoParaInput($a["periodo_desde"] ?? "");
        $hasta = periodoParaInput($a["periodo_hasta"] ?? "");
        $periodo = trim($desde . ($desde && $hasta ? " a " : "") . $hasta);
        $detalle = $cuotas . " x " . dinero($a["monto_cuota"] ?? 0);
        $partes[] = $tipo . ": Acuerdo " . $detalle . ($periodo ? " (" . $periodo . ")" : "");
    }
    return $partes ? implode(" | ", $partes) : "Sin acuerdo cargado";
}

if (isset($_POST["login"])) {
    $usuarioLogin = strtoupper(trim($_POST["usuario"] ?? ""));
    $passwordLogin = $_POST["password"] ?? "";
    if (isset($USUARIOS[$usuarioLogin]) && hash_equals($USUARIOS[$usuarioLogin], $passwordLogin)) {
        $_SESSION["auth_pagos_empresas"] = true;
        $_SESSION["usuario"] = $usuarioLogin;
        $_SESSION["usuario_logueado"] = $usuarioLogin;
        header("Location: index.php");
        exit;
    }
    $error = "Usuario o contraseña incorrectos";
}

if (isset($_GET["logout"])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION["auth_pagos_empresas"]) || usuarioActual() === "") {
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="favicon.png?v=<?php echo time(); ?>">
<title>Pagos Empresas</title>
<style>
body{margin:0;font-family:Arial;background:#f3f6f4;display:flex;align-items:center;justify-content:center;height:100vh}
.login{background:white;padding:30px;border-radius:16px;box-shadow:0 8px 25px #0002;width:330px}
h1{color:#087a46;margin-top:0}
input,button{width:100%;padding:12px;margin-top:12px;border-radius:8px;border:1px solid #ccc;box-sizing:border-box}
button{background:#087a46;color:white;border:0;font-weight:bold;cursor:pointer}
.error{color:#b00020;font-weight:bold}
</style>
</head>
<body>
<div class="login">
<h1>Pagos Empresas</h1>
<form method="post">
<input type="text" name="usuario" placeholder="Usuario" autocomplete="username" required>
<input type="password" name="password" placeholder="Contraseña" autocomplete="current-password" required>
<button name="login">Ingresar</button>
</form>
<?php if(isset($error)) echo "<p class='error'>".e($error)."</p>"; ?>
</div>
</body>
</html>
<?php exit; }

$esAdmin = usuarioEsAdmin();

$backupError = "";
if (isset($_GET["backup"])) {
    requerirAdmin();
    registrarAuditoria($auditoriaFile, "descargar_backup", "Descargó backup manual del sistema");
    if (!enviarBackupManual($empresasFile, $pagosFile, $auditoriaFile, $papeleraPagosFile, $uploadDir)) {
        $backupError = "No fue posible generar el backup.";
    }
}

$empresas = obtenerEmpresas();
$pagos = obtenerPagos();
$auditoria = obtenerAuditoria();
$papeleraPagos = obtenerPapeleraPagos();
$errorEmpresa = "";
$coincidenciasEmpresa = ["cuit" => null, "exacta" => null, "parecidas" => []];
$advertenciaEmpresa = false;
$errorPago = "";

if (isset($_GET["auditoria"]) && !$esAdmin) {
    accesoDenegado();
}

if (isset($_GET["exportar"]) && $_GET["exportar"] === "auditoria") {
    requerirAdmin();
    $filas = [];
    foreach (array_reverse($auditoria) as $movimiento) {
        $filas[] = [
            $movimiento["fecha"] ?? "",
            $movimiento["usuario"] ?? "",
            $movimiento["accion"] ?? "",
            $movimiento["detalle"] ?? ""
        ];
    }
    enviarCsv("auditoria_" . date("Y-m-d_H-i") . ".csv", ["Fecha", "Usuario", "Accion", "Detalle"], $filas);
}

if (isset($_GET["exportar"]) && $_GET["exportar"] === "pagos") {
    $filas = [];
    foreach (array_reverse($pagos) as $pago) {
        $empresa = buscarEmpresa($empresas, $pago["empresa_id"] ?? "");
        $filas[] = [
            $empresa["razon"] ?? "Empresa eliminada",
            $empresa["cuit"] ?? "",
            $pago["tipo"] ?? "",
            tipoPagoCompatible($pago, $empresas),
            periodoParaInput($pago["periodo"] ?? ""),
            $pago["fecha"] ?? "",
            $pago["forma_pago"] ?? "",
            floatval($pago["monto"] ?? 0),
            $pago["observaciones"] ?? ""
        ];
    }
    enviarCsv("pagos_registrados_" . date("Y-m-d_H-i") . ".csv", ["Empresa", "CUIT", "Tipo", "Tipo de pago", "Periodo", "Fecha de pago", "Forma de pago", "Monto", "Observaciones"], $filas);
}

if (isset($_GET["exportar"]) && $_GET["exportar"] === "informe") {
    $periodoExport = periodoParaInput($_GET["periodo"] ?? "");
    $tipoExport = $_GET["tipo"] ?? "";
    $tiposExport = in_array($tipoExport, ["Obra Social", "Sindicato", "Mutual"], true) ? [$tipoExport] : ["Obra Social", "Sindicato", "Mutual"];
    $filas = [];

    if (periodoValido($periodoExport)) {
        foreach ($empresas as $empresa) {
            if (!empresaActiva($empresa)) continue;
            foreach ($tiposExport as $tipo) {
                $acuerdo = acuerdoEmpresa($empresa, $tipo);
                $pagosPeriodo = array_values(array_filter($pagos, fn($p) =>
                    ($p["empresa_id"] ?? "") === ($empresa["id"] ?? "") &&
                    ($p["tipo"] ?? "") === $tipo &&
                    periodoParaInput($p["periodo"] ?? "") === $periodoExport
                ));
                $pagosPorTipo = [];
                foreach ($pagosPeriodo as $pagoPeriodo) {
                    $categoria = tipoPagoCompatible($pagoPeriodo, $empresas);
                    $pagosPorTipo[$categoria][] = $pagoPeriodo;
                }

                foreach (["Pago al día", "Cuota de acuerdo", "Pago único / extraordinario"] as $categoria) {
                    $pagosCategoria = $pagosPorTipo[$categoria] ?? [];
                    $esCuota = $categoria === "Cuota de acuerdo";
                    $aplicaAcuerdo = $esCuota && acuerdoValidoEmpresaTipo($empresa, $tipo) && periodoPerteneceAcuerdo($acuerdo, $periodoExport);
                    if ($categoria === "Pago único / extraordinario" && !$pagosCategoria) continue;
                    if ($esCuota && !$aplicaAcuerdo && !$pagosCategoria) continue;

                    $esperado = $aplicaAcuerdo ? floatval($acuerdo["monto_cuota"] ?? 0) : 0;
                    $pagado = array_reduce($pagosCategoria, fn($total, $p) => $total + floatval($p["monto"] ?? 0), 0);
                    $idsPrevios = array_flip(pagosPreviosIdsAcuerdo($acuerdo));
                    $tienePagoPrevioVinculado = $esCuota && count(array_filter($pagosCategoria, fn($p) => isset($idsPrevios[(string)($p["id"] ?? "")]))) > 0;

                    if ($categoria === "Pago al día") {
                        $estado = $pagosCategoria ? "PAGADO AL DÍA" : "PENDIENTE PAGO AL DÍA";
                    } elseif ($categoria === "Pago único / extraordinario") {
                        $estado = "PAGO EXTRAORDINARIO";
                    } elseif ($tienePagoPrevioVinculado) {
                        $estado = "PAGADA PREVIA REGISTRADA";
                    } elseif ($pagosCategoria) {
                        $estado = "CUOTA DE ACUERDO PAGADA";
                    } elseif (periodoEsCuotaPrevia($acuerdo, $periodoExport)) {
                        $estado = "PAGADA PREVIA";
                    } else {
                        $estado = "CUOTA DE ACUERDO PENDIENTE";
                    }

                    $pendiente = $esCuota && !in_array($estado, ["PAGADA PREVIA", "PAGADA PREVIA REGISTRADA"], true)
                        ? max($esperado - $pagado, 0)
                        : 0;
                    $filas[] = [
                        $empresa["razon"] ?? "",
                        $empresa["cuit"] ?? "",
                        $tipo,
                        $categoria,
                        $periodoExport,
                        implode(", ", array_filter(array_map(fn($p) => $p["fecha"] ?? "", $pagosCategoria))),
                        implode(", ", array_filter(array_map(fn($p) => $p["forma_pago"] ?? "", $pagosCategoria))),
                        $pagado,
                        implode(" | ", array_filter(array_map(fn($p) => $p["observaciones"] ?? "", $pagosCategoria))),
                        $estado,
                        $esperado,
                        $pendiente
                    ];
                }
            }
        }
    }

    enviarCsv("informe_periodo_" . ($periodoExport ? str_replace("/", "-", $periodoExport) : date("Y-m-d")) . ".csv", ["Empresa", "CUIT", "Tipo", "Tipo de pago", "Periodo", "Fecha de pago", "Forma de pago", "Monto", "Observaciones", "Estado", "Cuota esperada", "Pendiente"], $filas);
}

if (isset($_POST["guardar_empresa"])) {
    $id = $_POST["empresa_id"] ?: uniqid("emp_");
    $empresaExistente = buscarEmpresa($empresas, $id);
    $razonEmpresa = trim($_POST["razon"] ?? "");
    $cuitEmpresa = trim($_POST["cuit"] ?? "");
    $coincidenciasEmpresa = buscarCoincidenciasEmpresa($empresas, $razonEmpresa, $cuitEmpresa, $_POST["empresa_id"] ?? "");

    $nueva = [
        "id" => $id,
        "razon" => $razonEmpresa,
        "cuit" => $cuitEmpresa,
        "deuda_os" => floatval($empresaExistente["deuda_os"] ?? 0),
        "deuda_sindicato" => floatval($empresaExistente["deuda_sindicato"] ?? 0),
        "deuda_mutual" => floatval($empresaExistente["deuda_mutual"] ?? 0),
        "observaciones" => trim($_POST["observaciones_empresa"] ?? ""),
        "fecha_carga" => date("Y-m-d H:i:s"),
        "activa" => $empresaExistente ? empresaActiva($empresaExistente) : true
    ];

    if ($razonEmpresa === "") {
        $errorEmpresa = "La razón social es obligatoria.";
    } elseif ($coincidenciasEmpresa["cuit"]) {
        $errorEmpresa = "Ya existe una empresa cargada con ese CUIT.";
    } elseif ($coincidenciasEmpresa["exacta"]) {
        $errorEmpresa = "Esta empresa ya parece estar cargada.";
    } elseif (!empty($coincidenciasEmpresa["parecidas"]) && empty($_POST["confirmar_empresa_parecida"])) {
        $advertenciaEmpresa = true;
        $errorEmpresa = "Hay empresas parecidas ya cargadas. Revisá antes de guardar para evitar duplicados.";
    }

    if ($errorEmpresa === "") {
        $editado = false;
        foreach ($empresas as $k => $emp) {
            if (($emp["id"] ?? "") === $id) {
                $nueva["fecha_carga"] = $emp["fecha_carga"] ?? date("Y-m-d H:i:s");
                $nueva["acuerdos"] = $emp["acuerdos"] ?? [];
                foreach (["monto_total","cantidad_cuotas","monto_cuota","cuotas_pagadas_previas","periodo_desde","periodo_hasta","observaciones_acuerdo"] as $campoViejo) {
                    if (isset($emp[$campoViejo])) $nueva[$campoViejo] = $emp[$campoViejo];
                }
                $empresas[$k] = $nueva;
                $editado = true;
                break;
            }
        }

        if (!$editado) $empresas[] = $nueva;

        guardarEmpresas($empresas);
        registrarAuditoria(
            $auditoriaFile,
            $editado ? "editar_empresa" : "crear_empresa",
            ($editado ? "Editó empresa " : "Creó empresa ") . detalleEmpresa($nueva)
        );
        header("Location: index.php");
        exit;
    }
}

if (isset($_POST["guardar_acuerdo"])) {
    $empresaIdAcuerdo = $_POST["acuerdo_empresa_id"] ?? "";
    $tipoAcuerdo = $_POST["acuerdo_tipo"] ?? "";
    $montoTotalAcuerdo = floatval($_POST["acuerdo_monto_total"] ?? 0);
    $cantidadCuotasAcuerdo = intval($_POST["acuerdo_cantidad_cuotas"] ?? 2);
    $montoCuotaAcuerdo = floatval($_POST["acuerdo_monto_cuota"] ?? 0);
    $cuotasPreviasAcuerdo = intval($_POST["acuerdo_cuotas_pagadas_previas"] ?? 0);
    $pagosPreviosIdsAcuerdo = $_POST["pagos_previos_ids"] ?? [];
    $pagosPreviosIdsAcuerdo = is_array($pagosPreviosIdsAcuerdo)
        ? array_values(array_unique(array_filter(array_map("strval", $pagosPreviosIdsAcuerdo), fn($id) => $id !== "")))
        : [];
    $periodoDesdeAcuerdo = trim($_POST["acuerdo_periodo_desde"] ?? "");
    $periodoHastaAcuerdo = trim($_POST["acuerdo_periodo_hasta"] ?? "");
    $pagosPreviosValidos = pagosPreviosVinculados(
        ["pagos_previos_ids" => $pagosPreviosIdsAcuerdo],
        $pagos,
        $empresaIdAcuerdo,
        $tipoAcuerdo
    );

    if ($empresaIdAcuerdo === "") {
        $errorEmpresa = "Seleccioná una empresa para cargar el acuerdo.";
    } elseif (!in_array($tipoAcuerdo, ["Obra Social","Sindicato","Mutual"], true)) {
        $errorEmpresa = "Seleccioná un tipo de acuerdo válido.";
    } elseif ($montoTotalAcuerdo <= 0) {
        $errorEmpresa = "El monto total de la deuda debe ser mayor a 0.";
    } elseif ($cantidadCuotasAcuerdo < 2) {
        $errorEmpresa = "Para pago único usá la pestaña Cargar pago. Un acuerdo debe tener 2 cuotas o más.";
    } elseif ($montoCuotaAcuerdo <= 0) {
        $errorEmpresa = "El monto de cada cuota debe ser mayor a 0.";
    } elseif ($cuotasPreviasAcuerdo < 0) {
        $errorEmpresa = "Las cuotas ya pagadas no pueden ser negativas.";
    } elseif ($cuotasPreviasAcuerdo >= $cantidadCuotasAcuerdo) {
        $errorEmpresa = "Las cuotas previas ya pagadas deben ser menores que la cantidad total de cuotas.";
    } elseif (count($pagosPreviosIdsAcuerdo) > $cuotasPreviasAcuerdo) {
        $errorEmpresa = "Los pagos previos vinculados no pueden superar las cuotas previas declaradas.";
    } elseif (count($pagosPreviosValidos) !== count($pagosPreviosIdsAcuerdo)) {
        $errorEmpresa = "Solo se pueden vincular pagos existentes de la misma empresa y tipo.";
    } elseif ($periodoDesdeAcuerdo === "") {
        $errorEmpresa = "El período es obligatorio.";
    } elseif ($periodoHastaAcuerdo === "") {
        $errorEmpresa = "El período hasta es obligatorio.";
    } elseif ($periodoDesdeAcuerdo !== "" && !periodoValido($periodoDesdeAcuerdo)) {
        $errorEmpresa = "El período desde debe tener formato MM/AA.";
    } elseif ($periodoHastaAcuerdo !== "" && !periodoValido($periodoHastaAcuerdo)) {
        $errorEmpresa = "El período hasta debe tener formato MM/AA.";
    } elseif (periodoAIndice($periodoHastaAcuerdo) < periodoAIndice($periodoDesdeAcuerdo)) {
        $errorEmpresa = "El período hasta no puede ser anterior al período desde.";
    }

    if ($errorEmpresa === "") {
        $acuerdoEditado = false;
        $empresaAuditada = null;
        foreach ($empresas as $k => $emp) {
            if (($emp["id"] ?? "") === $empresaIdAcuerdo) {
                $empresaAuditada = $emp;
                $acuerdoEditado = isset($emp["acuerdos"]) && is_array($emp["acuerdos"]) && isset($emp["acuerdos"][$tipoAcuerdo]);
                if (!isset($empresas[$k]["acuerdos"]) || !is_array($empresas[$k]["acuerdos"])) {
                    $empresas[$k]["acuerdos"] = [];
                }
                $empresas[$k]["acuerdos"][$tipoAcuerdo] = [
                    "monto_total" => $montoTotalAcuerdo,
                    "cantidad_cuotas" => $cantidadCuotasAcuerdo,
                    "monto_cuota" => $montoCuotaAcuerdo,
                    "cuotas_pagadas_previas" => $cuotasPreviasAcuerdo,
                    "pagos_previos_ids" => $pagosPreviosIdsAcuerdo,
                    "periodo_desde" => $periodoDesdeAcuerdo,
                    "periodo_hasta" => $periodoHastaAcuerdo,
                    "observaciones" => trim($_POST["acuerdo_observaciones"] ?? "")
                ];
                break;
            }
        }

        guardarEmpresas($empresas);
        registrarAuditoria(
            $auditoriaFile,
            $acuerdoEditado ? "editar_acuerdo" : "crear_acuerdo",
            ($acuerdoEditado ? "Editó acuerdo de " : "Creó acuerdo de ") . (($empresaAuditada["razon"] ?? "Empresa") . " - " . $tipoAcuerdo . " - " . $periodoDesdeAcuerdo . " a " . $periodoHastaAcuerdo . " - " . dinero($montoTotalAcuerdo) . " - pagos previos vinculados: " . count($pagosPreviosIdsAcuerdo))
        );
        header("Location: index.php#cargar-acuerdo");
        exit;
    }
}

if (isset($_POST["guardar_pago"])) {
    $pagoIdActual = trim($_POST["pago_id"] ?? "");
    $id = $pagoIdActual !== "" ? $pagoIdActual : uniqid("pago_");
    $comprobante = $_POST["comprobante_actual"] ?? "";
    $periodo = trim($_POST["periodo"] ?? "");
    $empresaIdPago = $_POST["empresa_id"] ?? "";
    $tipoPago = $_POST["tipo"] ?? "";
    $tipoDePago = $_POST["tipo_pago"] ?? "";
    $formaPago = $_POST["forma_pago"] ?? "";
    $empresaPago = buscarEmpresa($empresas, $empresaIdPago);
    $pagoExistente = null;
    foreach ($pagos as $pagoGuardado) {
        if (($pagoGuardado["id"] ?? "") === $id) {
            $pagoExistente = $pagoGuardado;
            break;
        }
    }

    $cheques = [];
    $fechasCheque = isset($_POST["cheque_fecha"]) && is_array($_POST["cheque_fecha"]) ? $_POST["cheque_fecha"] : [];
    $indicesCheque = isset($_POST["cheque_indice"]) && is_array($_POST["cheque_indice"]) ? $_POST["cheque_indice"] : [];
    $chequesExistentes = chequesPago($pagoExistente);

    if ($formaPago === "Cheque") {
        foreach ($fechasCheque as $posicion => $fechaCheque) {
            $fechaCheque = trim($fechaCheque ?? "");
            $indiceOriginal = isset($indicesCheque[$posicion]) && preg_match('/^\d+$/', (string)$indicesCheque[$posicion])
                ? intval($indicesCheque[$posicion])
                : -1;
            $chequeExistente = $indiceOriginal >= 0 && isset($chequesExistentes[$indiceOriginal]) && is_array($chequesExistentes[$indiceOriginal])
                ? $chequesExistentes[$indiceOriginal]
                : [];
            $cheques[] = [
                "fecha_cobro" => $fechaCheque,
                "cobrado" => !empty($chequeExistente["cobrado"]),
                "fecha_marcado_cobrado" => $chequeExistente["fecha_marcado_cobrado"] ?? "",
                "usuario_cobrado" => $chequeExistente["usuario_cobrado"] ?? ""
            ];
        }
    }
    $hayFechaChequeNoFutura = false;
    foreach ($cheques as $posicion => $cheque) {
        $indiceOriginal = isset($indicesCheque[$posicion]) && preg_match('/^\d+$/', (string)$indicesCheque[$posicion])
            ? intval($indicesCheque[$posicion])
            : -1;
        $fechaOriginal = $indiceOriginal >= 0 ? trim($chequesExistentes[$indiceOriginal]["fecha_cobro"] ?? "") : "";
        $fechaCheque = trim($cheque["fecha_cobro"] ?? "");
        if ($fechaCheque <= date("Y-m-d") && $fechaCheque !== $fechaOriginal) {
            $hayFechaChequeNoFutura = true;
            break;
        }
    }

    if (!in_array($tipoDePago, ["Pago al día", "Cuota de acuerdo", "Pago único / extraordinario"], true)) {
        $errorPago = "Seleccioná un tipo de pago válido.";
    } elseif (!periodoValido($periodo)) {
        $errorPago = "El periodo debe tener formato MM/AA.";
    } elseif ($tipoDePago === "Cuota de acuerdo" && (!$empresaPago || !acuerdoValidoEmpresaTipo($empresaPago, $tipoPago))) {
        $errorPago = "Para cargar una cuota debe existir un acuerdo para esta empresa y este tipo.";
    } elseif ($tipoDePago === "Cuota de acuerdo" && !periodoPerteneceAcuerdo(acuerdoEmpresa($empresaPago, $tipoPago), $periodo)) {
        $errorPago = "El período seleccionado no pertenece al acuerdo.";
    } elseif ($tipoDePago === "Cuota de acuerdo" && periodoEsCuotaPrevia(acuerdoEmpresa($empresaPago, $tipoPago), $periodo)) {
        $errorPago = "El período seleccionado ya está cubierto por una cuota previa pagada.";
    } elseif (existePagoEmpresaTipoPeriodoTipoPago($pagos, $empresas, $empresaIdPago, $tipoPago, $periodo, $tipoDePago, $pagoIdActual)) {
        $errorPago = "Ya existe un pago cargado para esta empresa, este tipo, este período y este tipo de pago.";
    } elseif ($formaPago === "Cheque" && count($cheques) < 1) {
        $errorPago = "Ingresá al menos una fecha de cobro de cheque.";
    } elseif ($formaPago === "Cheque" && count(array_filter($cheques, fn($cheque) => trim($cheque["fecha_cobro"] ?? "") === "")) > 0) {
        $errorPago = "Todas las fechas de cobro de los cheques deben estar completas.";
    } elseif ($formaPago === "Cheque" && count(array_filter($cheques, fn($cheque) => !fechaChequeValida($cheque["fecha_cobro"] ?? ""))) > 0) {
        $errorPago = "Todas las fechas de cobro de los cheques deben ser válidas.";
    } elseif ($formaPago === "Cheque" && $hayFechaChequeNoFutura) {
        $errorPago = "Las fechas de cobro de los cheques deben ser futuras.";
    } elseif (!empty($_FILES["comprobante"]["name"])) {
        $ext = strtolower(pathinfo($_FILES["comprobante"]["name"], PATHINFO_EXTENSION));
        $permitidos = ["pdf", "jpg", "jpeg", "png"];

        if (in_array($ext, $permitidos)) {
            $empresa = buscarEmpresa($empresas, $_POST["empresa_id"]);
            $cuit = preg_replace('/[^0-9]/', '', $empresa["cuit"] ?? "");
            $razon = limpiarArchivo($empresa["razon"] ?? "empresa");
            $nombreArchivo = date("Ymd_His") . "_" . $cuit . "_" . $razon . "." . $ext;

            if (move_uploaded_file($_FILES["comprobante"]["tmp_name"], $uploadDir . "/" . $nombreArchivo)) {
                $comprobante = "comprobantes/" . $nombreArchivo;
            }
        }
    }

    if ($errorPago === "") {
        $nuevo = [
            "id" => $id,
            "empresa_id" => $_POST["empresa_id"] ?? "",
            "fecha" => $_POST["fecha"] ?? "",
            "tipo" => $_POST["tipo"] ?? "",
            "forma_pago" => $_POST["forma_pago"] ?? "",
            "monto" => floatval($_POST["monto"] ?? 0),
            "tipo_pago" => $tipoDePago,
            "periodo" => $periodo,
            "comprobante" => $comprobante,
            "observaciones" => trim($_POST["observaciones_pago"] ?? ""),
            "fecha_carga" => date("Y-m-d H:i:s")
        ];

        if ($formaPago === "Cheque") {
            $nuevo["cheques"] = $cheques;
        }

        foreach (["pago_tipo", "cuotas"] as $campoHistorico) {
            if (is_array($pagoExistente) && array_key_exists($campoHistorico, $pagoExistente)) {
                $nuevo[$campoHistorico] = $pagoExistente[$campoHistorico];
            }
        }

        $editado = false;
        foreach ($pagos as $k => $p) {
            if (($p["id"] ?? "") === $id) {
                $nuevo["fecha_carga"] = $p["fecha_carga"] ?? date("Y-m-d H:i:s");
                $pagos[$k] = $nuevo;
                $editado = true;
                break;
            }
        }

        if (!$editado) $pagos[] = $nuevo;

        guardarPagos($pagos);
        registrarAuditoria(
            $auditoriaFile,
            $editado ? "editar_pago" : "crear_pago",
            ($editado ? "Editó pago de " : "Cargó pago de ") . detallePago($nuevo, $empresas) . " - " . $tipoDePago
        );
        if (!$editado && count($cheques) > 0) {
            registrarAuditoria(
                $auditoriaFile,
                "crear_pago_cheques",
                "Cargó pago con " . count($cheques) . " cheque(s) de " . detallePago($nuevo, $empresas)
            );
        } elseif ($editado && fechasCheques(chequesPago($pagoExistente)) !== fechasCheques($cheques)) {
            registrarAuditoria(
                $auditoriaFile,
                "editar_fechas_cheques",
                "Editó fechas de cobro de cheques de " . detallePago($nuevo, $empresas)
            );
        }
        header("Location: index.php");
        exit;
    }
}

if (isset($_POST["marcar_cheque_cobrado"])) {
    $pagoIdCheque = trim($_POST["pago_id"] ?? "");
    $indiceCheque = filter_var($_POST["cheque_indice"] ?? null, FILTER_VALIDATE_INT);
    $empresaCheque = null;

    if ($pagoIdCheque !== "" && $indiceCheque !== false && $indiceCheque >= 0) {
        foreach ($pagos as $pagoIndice => $pago) {
            if (($pago["id"] ?? "") !== $pagoIdCheque) continue;
            if (!isset($pagos[$pagoIndice]["cheques"]) || !is_array($pagos[$pagoIndice]["cheques"])) break;
            if (!isset($pagos[$pagoIndice]["cheques"][$indiceCheque]) || !is_array($pagos[$pagoIndice]["cheques"][$indiceCheque])) break;
            if (!empty($pagos[$pagoIndice]["cheques"][$indiceCheque]["cobrado"])) break;

            $pagos[$pagoIndice]["cheques"][$indiceCheque]["cobrado"] = true;
            $pagos[$pagoIndice]["cheques"][$indiceCheque]["fecha_marcado_cobrado"] = date("Y-m-d H:i:s");
            $pagos[$pagoIndice]["cheques"][$indiceCheque]["usuario_cobrado"] = usuarioActual();
            $empresaCheque = buscarEmpresa($empresas, $pago["empresa_id"] ?? "");
            $fechaChequeAuditada = $pagos[$pagoIndice]["cheques"][$indiceCheque]["fecha_cobro"] ?? "";
            guardarPagos($pagos);
            registrarAuditoria(
                $auditoriaFile,
                "marcar_cheque_cobrado",
                usuarioActual() . " marcó como cobrado cheque con fecha " . fechaParaMostrar($fechaChequeAuditada) . " de " . ($empresaCheque["razon"] ?? "Empresa eliminada")
            );
            break;
        }
    }

    $empresaIdDestino = trim($_POST["empresa_id_destino"] ?? "");
    $destino = $empresaIdDestino !== ""
        ? "index.php?ver_empresa=" . urlencode($empresaIdDestino) . "#buscar-empresa"
        : "index.php";
    header("Location: " . $destino);
    exit;
}

if (isset($_GET["eliminar_empresa"])) {
    $id = $_GET["eliminar_empresa"];
    $empresaBaja = null;
    foreach ($empresas as $k => $empresa) {
        if (($empresa["id"] ?? "") === $id) {
            $empresas[$k]["activa"] = false;
            $empresas[$k]["fecha_baja"] = date("Y-m-d H:i:s");
            $empresas[$k]["baja_por"] = usuarioActual();
            $empresaBaja = $empresas[$k];
            break;
        }
    }
    guardarEmpresas($empresas);
    if ($empresaBaja) {
        registrarAuditoria($auditoriaFile, "dar_de_baja_empresa", "Dio de baja empresa " . detalleEmpresa($empresaBaja));
    }
    header("Location: index.php");
    exit;
}

if (isset($_GET["eliminar_pago"])) {
    $id = $_GET["eliminar_pago"];
    $pagoEliminado = null;
    foreach ($pagos as $p) {
        if (($p["id"] ?? "") === $id) {
            $pagoEliminado = $p;
            break;
        }
    }
    if ($pagoEliminado) {
        $pagoEliminado["eliminado_por"] = usuarioActual();
        $pagoEliminado["fecha_eliminacion"] = date("Y-m-d H:i:s");
        $pagoEliminado["motivo"] = trim($_GET["motivo"] ?? "");
        $papeleraPagos[] = $pagoEliminado;
        $pagos = array_values(array_filter($pagos, fn($p) => ($p["id"] ?? "") !== $id));
        guardarPagos($pagos);
        guardarPapeleraPagos($papeleraPagos);
        registrarAuditoria($auditoriaFile, "eliminar_pago", "Eliminó pago de " . detallePago($pagoEliminado, $empresas));
    }
    header("Location: index.php");
    exit;
}

if (isset($_GET["eliminar_acuerdo"], $_GET["tipo_acuerdo"])) {
    $empresaId = $_GET["eliminar_acuerdo"];
    $tipoAcuerdoEliminar = $_GET["tipo_acuerdo"];

    if (in_array($tipoAcuerdoEliminar, ["Obra Social", "Sindicato", "Mutual"], true)) {
        foreach ($empresas as $k => $empresa) {
            if (($empresa["id"] ?? "") !== $empresaId) continue;
            if (isset($empresas[$k]["acuerdos"]) && is_array($empresas[$k]["acuerdos"])) {
                unset($empresas[$k]["acuerdos"][$tipoAcuerdoEliminar]);
            }
            registrarAuditoria($auditoriaFile, "eliminar_acuerdo", "Eliminó acuerdo de " . (($empresas[$k]["razon"] ?? "Empresa") . " - " . $tipoAcuerdoEliminar));
            break;
        }
        guardarEmpresas($empresas);
    }

    $destino = ($_GET["origen"] ?? "") === "ficha" ? "buscar-empresa" : "cargar-acuerdo";
    header("Location: index.php#" . $destino);
    exit;
}

if (isset($_GET["eliminar_comprobante"])) {
    $id = $_GET["eliminar_comprobante"];

    foreach ($pagos as $k => $p) {
        if (($p["id"] ?? "") === $id) {
            $rutaFisica = rutaComprobanteFisico($p["comprobante"] ?? "", $uploadDir);
            if ($rutaFisica && is_file($rutaFisica)) {
                @unlink($rutaFisica);
            }

            $pagos[$k]["comprobante"] = "";
            break;
        }
    }

    guardarPagos($pagos);
    header("Location: index.php?editar_pago=" . urlencode($id));
    exit;
}

$editarEmpresa = null;
if (isset($_GET["editar_empresa"])) {
    $editarEmpresa = buscarEmpresa($empresas, $_GET["editar_empresa"]);
}

if ($errorEmpresa !== "" && isset($_POST["guardar_empresa"])) {
    $editarEmpresa = [
        "id" => $_POST["empresa_id"] ?? "",
        "razon" => $_POST["razon"] ?? "",
        "cuit" => $_POST["cuit"] ?? "",
        "deuda_os" => $_POST["deuda_os"] ?? "",
        "deuda_sindicato" => $_POST["deuda_sindicato"] ?? "",
        "deuda_mutual" => $_POST["deuda_mutual"] ?? "",
        "observaciones" => $_POST["observaciones_empresa"] ?? ""
    ];
}

$acuerdoForm = [
    "empresa_id" => $_POST["acuerdo_empresa_id"] ?? "",
    "tipo" => $_POST["acuerdo_tipo"] ?? "",
    "monto_total" => $_POST["acuerdo_monto_total"] ?? "",
    "cantidad_cuotas" => $_POST["acuerdo_cantidad_cuotas"] ?? "",
    "monto_cuota" => $_POST["acuerdo_monto_cuota"] ?? "",
    "cuotas_pagadas_previas" => $_POST["acuerdo_cuotas_pagadas_previas"] ?? "0",
    "pagos_previos_ids" => isset($_POST["pagos_previos_ids"]) && is_array($_POST["pagos_previos_ids"]) ? $_POST["pagos_previos_ids"] : [],
    "periodo_desde" => $_POST["acuerdo_periodo_desde"] ?? "",
    "periodo_hasta" => $_POST["acuerdo_periodo_hasta"] ?? "",
    "observaciones" => $_POST["acuerdo_observaciones"] ?? ""
];

$editarPago = null;
if (isset($_GET["editar_pago"])) {
    foreach ($pagos as $p) {
        if (($p["id"] ?? "") === $_GET["editar_pago"]) {
            $editarPago = $p;
            $editarPago["tipo_pago"] = tipoPagoCompatible($p, $empresas);
            break;
        }
    }
}

if ($errorPago !== "" && isset($_POST["guardar_pago"])) {
    $editarPago = [
        "id" => $_POST["pago_id"] ?? "",
        "empresa_id" => $_POST["empresa_id"] ?? "",
        "fecha" => $_POST["fecha"] ?? date("Y-m-d"),
        "tipo" => $_POST["tipo"] ?? "",
        "forma_pago" => $_POST["forma_pago"] ?? "",
        "monto" => $_POST["monto"] ?? "",
        "tipo_pago" => $_POST["tipo_pago"] ?? "",
        "periodo" => $_POST["periodo"] ?? "",
        "comprobante" => $_POST["comprobante_actual"] ?? "",
        "observaciones" => $_POST["observaciones_pago"] ?? "",
        "cheques" => $cheques ?? []
    ];
}

$totalCobrado = 0;
$cobradoOS = 0;
$cobradoSindicato = 0;
$cobradoMutual = 0;
$cantidadPagos = count($pagos);
$periodoActual = date("m/y");
$mesActual = date("Y-m");
$empresasActivas = count(array_filter($empresas, fn($empresa) => empresaActiva($empresa)));
$pagosEsteMes = 0;
$totalCobradoEsteMes = 0;

foreach ($pagos as $p) {
    $monto = floatval($p["monto"] ?? 0);
    $totalCobrado += $monto;
    if (substr($p["fecha"] ?? "", 0, 7) === $mesActual) {
        $pagosEsteMes++;
        $totalCobradoEsteMes += $monto;
    }

    if (($p["tipo"] ?? "") === "Obra Social") {
        $cobradoOS += $monto;
    } elseif (($p["tipo"] ?? "") === "Sindicato") {
        $cobradoSindicato += $monto;
    } elseif (($p["tipo"] ?? "") === "Mutual") {
        $cobradoMutual += $monto;
    }
}

$totalCobrado = max($totalCobrado, 0);
$cobradoOS = max($cobradoOS, 0);
$cobradoSindicato = max($cobradoSindicato, 0);
$cobradoMutual = max($cobradoMutual, 0);
$totalCobradoEsteMes = max($totalCobradoEsteMes, 0);
$deudoresPeriodoActual = 0;
foreach ($empresas as $empresa) {
    if (!empresaActiva($empresa)) continue;
    foreach (["Obra Social", "Sindicato", "Mutual"] as $tipo) {
        if (!existePagoEmpresaTipoPeriodoTipoPago($pagos, $empresas, $empresa["id"] ?? "", $tipo, $periodoActual, "Pago al día")) {
            $deudoresPeriodoActual++;
        }
    }
}
$chequesAlertas = [];
$chequesVencenHoy = 0;
$chequesVencidos = 0;
foreach ($pagos as $pago) {
    foreach (chequesPago($pago) as $indiceCheque => $cheque) {
        if (!empty($cheque["cobrado"])) continue;
        $estado = estadoCheque($cheque);
        if ($estado !== "Vence hoy" && $estado !== "Vencido") continue;
        $empresaCheque = buscarEmpresa($empresas, $pago["empresa_id"] ?? "");
        $chequesAlertas[] = [
            "pago_id" => $pago["id"] ?? "",
            "cheque_indice" => $indiceCheque,
            "empresa_id" => $pago["empresa_id"] ?? "",
            "empresa" => $empresaCheque["razon"] ?? "Empresa eliminada",
            "fecha_cobro" => $cheque["fecha_cobro"] ?? "",
            "estado" => $estado
        ];
        if ($estado === "Vence hoy") {
            $chequesVencenHoy++;
        } else {
            $chequesVencidos++;
        }
    }
}
usort($chequesAlertas, fn($a, $b) => strcmp($a["fecha_cobro"], $b["fecha_cobro"]));
$cantidadAlertasCheques = count($chequesAlertas);
$tabInicial = $editarPago
    ? "cargar-pago"
    : ($editarEmpresa
        ? "nueva-empresa"
        : (isset($_GET["ver_empresa"])
            ? "buscar-empresa"
            : ((isset($_POST["guardar_acuerdo"]) && $errorEmpresa !== "") ? "cargar-acuerdo" : "inicio")));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="favicon.png?v=<?php echo time(); ?>">
<title>Registro de Pagos Empresas</title>
<style>
body{margin:0;font-family:Arial;background:#f3f6f4;color:#222}
header{background:#087a46;color:white;padding:18px 25px;display:flex;justify-content:space-between;align-items:center}
header h1{margin:0;font-size:24px}
header a{color:white;text-decoration:none;font-weight:bold}
.header-actions{display:flex;gap:12px;align-items:center}
.usuario-header{font-weight:bold;color:#eaf7f0}
.notificaciones{position:relative}
.notificaciones-toggle{width:auto;background:#fff;color:#087a46;padding:8px 11px}
.notificaciones-panel{display:none;position:absolute;right:0;top:calc(100% + 10px);width:min(380px,90vw);max-height:70vh;overflow:auto;background:white;color:#222;border-radius:12px;box-shadow:0 10px 30px #0004;padding:12px;z-index:30}
.notificaciones.abierta .notificaciones-panel{display:block}
.notificacion-cheque{padding:10px 0;border-bottom:1px solid #ddd}
.notificacion-cheque:last-child{border-bottom:0}
.notificacion-cheque form{margin-top:8px}
.notificacion-cheque button{width:auto;padding:7px 10px}
main{padding:20px}
.tabs{display:flex;flex-wrap:wrap;gap:8px;background:white;padding:12px 20px;border-bottom:1px solid #dcefe6;position:sticky;top:0;z-index:5}
.tab-btn{width:auto;background:#eaf7f0;color:#087a46;border:1px solid #b9dfcc;padding:10px 13px}
.tab-btn.active{background:#087a46;color:white;border-color:#087a46}
.tab-panel{display:none}
.tab-panel.active{display:block}
.home-actions{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-top:16px}
.home-actions button{min-height:64px;font-size:16px}
.empresa-ficha{background:#f7fbf9;border:1px solid #dcefe6;border-radius:12px;padding:16px;margin:16px 0}
.empresa-ficha-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.search-results{margin-top:12px}
.search-result{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px;border-bottom:1px solid #dcefe6}
.search-result button{width:auto}
.empresa-picker{position:relative}
.empresa-picker-input{width:100%}
.empresa-picker-results{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:white;border:1px solid #b9dfcc;border-radius:8px;box-shadow:0 8px 20px #0002;max-height:220px;overflow-y:auto;z-index:20}
.empresa-picker-results.active{display:block}
.empresa-picker-option{padding:10px;cursor:pointer;border-bottom:1px solid #eaf7f0}
.empresa-picker-option:hover{background:#eaf7f0;color:#087a46}
.card{background:white;border-radius:16px;box-shadow:0 5px 18px #0001;padding:20px;margin-bottom:20px}
.card-header{display:flex;justify-content:space-between;align-items:center;gap:12px}
.card-header h2{margin:0}
.toggle-card{width:auto;background:#eaf7f0;color:#087a46;border:1px solid #b9dfcc;padding:8px 12px}
.card-body{margin-top:16px}
.card.is-collapsed .card-body{display:none}
.quick-actions{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px}
.quick-actions button{width:auto}
.resumen{display:grid;grid-template-columns:repeat(6,1fr);gap:15px}
.box{background:#eaf7f0;padding:18px;border-radius:14px}
.label{font-size:14px;color:#555}
.num{font-size:26px;font-weight:bold;color:#087a46;margin-top:5px}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
input,select,textarea,button{padding:10px;border:1px solid #ccc;border-radius:8px;font-size:14px;box-sizing:border-box}
textarea{width:100%;height:65px;margin-top:12px}
.campo{display:flex;flex-direction:column;gap:6px}
.campo label{font-size:13px;font-weight:bold;color:#34443d}
.campo input,.campo select,.campo textarea,.campo .empresa-picker{width:100%}
.campo textarea{margin-top:0}
.plan-acuerdo{margin:0 0 14px;padding:14px 16px;border-radius:12px;background:#eaf7f0;border:1px solid #b9dfcc;color:#087a46;font-size:18px;font-weight:bold}
.plan-acuerdo small{display:block;margin-top:5px;color:#34443d;font-size:14px}
.resumen-acuerdo{margin-top:18px;padding:16px;border:1px solid #b9dfcc;border-radius:12px;background:#f7fbf9}
.resumen-acuerdo h3{margin:0 0 10px;color:#087a46}
.resumen-acuerdo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.resumen-acuerdo-item{background:white;border-radius:8px;padding:10px}
.resumen-acuerdo-item strong{display:block;color:#555;font-size:12px;margin-bottom:4px}
.pagos-previos-selector{margin-top:18px;padding:16px;border:1px solid #b9dfcc;border-radius:12px;background:#f7fbf9}
.pagos-previos-selector h3{margin:0 0 6px;color:#087a46}
.pagos-previos-selector table{margin-top:12px;background:white}
.pagos-previos-selector input[type="checkbox"]{width:auto}
.pago-fuera-periodo{background:#fff7e6}
.advertencia{color:#8a5a00;font-size:12px}
button{background:#087a46;color:white;border:0;font-weight:bold;cursor:pointer}
.btn-cancelar{display:inline-block;background:#777;color:white;padding:10px 14px;border-radius:8px;text-decoration:none;margin-left:8px}
.btn-secundario{display:inline-block;background:#eaf7f0;color:#087a46;border:1px solid #b9dfcc;padding:9px 12px;border-radius:8px;text-decoration:none;font-weight:bold;margin-right:8px}
.btn-danger{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;background:#b00020;color:white;border:0;padding:0;border-radius:8px;text-decoration:none;font-size:16px;line-height:1;vertical-align:middle}
.btn-danger:hover{background:#8f001a}
button,
.btn-cancelar,
.btn-secundario,
.btn-danger,
.btn-small,
.tab-btn,
.tab-jump,
.toggle-card,
.acciones a{
    transition:transform 0.08s ease,box-shadow 0.08s ease,filter 0.08s ease;
}
button:hover,
.btn-cancelar:hover,
.btn-secundario:hover,
.btn-danger:hover,
.btn-small:hover,
.tab-btn:hover,
.tab-jump:hover,
.toggle-card:hover,
.acciones a:hover{
    filter:brightness(0.96);
}
button:active,
.btn-cancelar:active,
.btn-secundario:active,
.btn-danger:active,
.btn-small:active,
.tab-btn:active,
.tab-jump:active,
.toggle-card:active,
.acciones a:active{
    transform:translateY(2px) scale(0.98);
    box-shadow:inset 0 2px 5px rgba(0,0,0,0.25);
}
button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible{
    outline:3px solid rgba(8,122,70,0.25);
    outline-offset:2px;
}
.filters{background:#f7fbf9;border:1px solid #dcefe6;border-radius:12px;padding:14px;margin:12px 0 16px}
.filters-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:10px;align-items:center}
.filters-grid.empresas{grid-template-columns:1.2fr 1.2fr 2fr 1fr auto}
.filters-grid.informe{grid-template-columns:1fr 1fr auto auto}
.filters-grid.auditoria{grid-template-columns:1fr 1.2fr 2fr 1fr 1fr auto auto}
.filters input,.filters select{width:100%;background:white}
.filters button{white-space:nowrap}
.informe-resumen{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:12px 0 16px}
.informe-resumen .box{padding:14px}
.mini-title{margin:18px 0 8px;color:#087a46}
.btn-small{display:inline-block;background:#087a46;color:white;border:0;padding:7px 10px;border-radius:8px;text-decoration:none;font-weight:bold;cursor:pointer}
.estado{display:inline-block;padding:4px 8px;border-radius:20px;font-weight:bold;font-size:12px}
.estado-ok{background:#eaf7f0;color:#087a46}
.estado-previa{background:#e8f0ff;color:#2255aa}
.estado-parcial{background:#fff4df;color:#b76500}
.estado-deudor{background:#fde7eb;color:#b00020}
table{width:100%;border-collapse:collapse}
table thead,
table th,
thead th{
    position:static !important;
    top:auto !important;
    z-index:auto !important;
}
th,td{padding:10px;border-bottom:1px solid #ddd;text-align:left;font-size:14px}
th{background:#087a46;color:white}
.acciones a{text-decoration:none;margin-right:8px;font-size:18px}
.badge{background:#eaf7f0;color:#087a46;padding:4px 8px;border-radius:20px;font-weight:bold;font-size:12px}
.sin{color:#999}
.saldo-ok{color:#087a46;font-weight:bold}
.saldo-debe{color:#b00020;font-weight:bold}
.error{color:#b00020;font-weight:bold}
.empresa-duplicados{margin:14px 0;padding:14px;border-radius:12px;background:#fff7e6;border:1px solid #e2b75b}
.empresa-duplicados.bloqueo{background:#fdebed;border-color:#d98b98}
.empresa-duplicados p{margin:0 0 10px}
.empresa-coincidencia{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 0;border-top:1px solid #0001}
.empresa-coincidencia:first-of-type{border-top:0}
.empresa-coincidencia button{width:auto;white-space:nowrap}
.cheques-pago{margin-top:16px;padding:16px;border:1px solid #b9dfcc;border-radius:12px;background:#f7fbf9}
.cheques-pago h3{margin:0 0 12px;color:#087a46}
.cheques-fechas{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:12px}
.cheque-fecha-item{display:flex;gap:8px;align-items:end}
.cheque-fecha-item .campo{flex:1}
.quitar-cheque{width:auto;background:#b00020;padding:10px 12px}
.fila-oculta{display:none}
@media(max-width:1000px){.grid,.resumen,.home-actions,.empresa-ficha-grid,.cheques-fechas,.filters-grid,.filters-grid.empresas,.filters-grid.informe,.filters-grid.auditoria,.informe-resumen,.resumen-acuerdo-grid{grid-template-columns:1fr}table{display:block;overflow-x:auto}}
</style>
</head>
<body>

<header>
<h1>Registro de Pagos - Empresas Deudoras</h1>
<div class="header-actions">
<div class="notificaciones" id="notificacionesCheques">
<button type="button" class="notificaciones-toggle" id="notificacionesToggle" aria-expanded="false" aria-controls="notificacionesPanel">&#x1F514; <?= e($cantidadAlertasCheques) ?></button>
<div class="notificaciones-panel" id="notificacionesPanel">
<strong>Cheques por atender</strong>
<?php if(empty($chequesAlertas)): ?>
<p class="sin">No hay cheques vencidos ni que venzan hoy.</p>
<?php endif; ?>
<?php foreach($chequesAlertas as $alertaCheque): ?>
<div class="notificacion-cheque">
<div><strong><?= e($alertaCheque["empresa"]) ?></strong></div>
<div><?= e(fechaParaMostrar($alertaCheque["fecha_cobro"])) ?></div>
<div><span class="estado <?= $alertaCheque["estado"] === "Vencido" ? "estado-deudor" : "estado-parcial" ?>"><?= e($alertaCheque["estado"]) ?></span></div>
<form method="post">
<input type="hidden" name="pago_id" value="<?= e($alertaCheque["pago_id"]) ?>">
<input type="hidden" name="cheque_indice" value="<?= e($alertaCheque["cheque_indice"]) ?>">
<button name="marcar_cheque_cobrado" value="1">&#x2713; Marcar cobrado</button>
</form>
</div>
<?php endforeach; ?>
</div>
</div>
<span class="usuario-header">Usuario: <?= e(usuarioActual()) ?></span>
<?php if ($esAdmin): ?>
<a href="?backup=1">&#x2B07; Backup</a>
<?php endif; ?>
<a href="?logout=1">Salir</a>
</div>
</header>

<nav class="tabs">
<button type="button" class="tab-btn active" data-tab="inicio">Inicio</button>
<button type="button" class="tab-btn" data-tab="buscar-empresa">Buscar empresa</button>
<button type="button" class="tab-btn" data-tab="cargar-pago">Cargar pago</button>
<button type="button" class="tab-btn" data-tab="cargar-acuerdo">Cargar acuerdo</button>
<button type="button" class="tab-btn" data-tab="nueva-empresa">Nueva empresa</button>
<button type="button" class="tab-btn" data-tab="informe-periodo">Informe período</button>
<button type="button" class="tab-btn" data-tab="pagos">Pagos registrados</button>
<?php if ($esAdmin): ?>
<button type="button" class="tab-btn" data-tab="auditoria">Auditoría</button>
<?php endif; ?>
</nav>

<main>
<?php if ($backupError !== ""): ?>
<p class="error"><?= e($backupError) ?></p>
<?php endif; ?>

<section class="tab-panel active" id="tab-inicio">
<div class="card resumen">
<div class="box"><div class="label">Empresas activas</div><div class="num"><?= e($empresasActivas) ?></div></div>
<div class="box"><div class="label">Pagos registrados este mes</div><div class="num"><?= e($pagosEsteMes) ?></div></div>
<div class="box"><div class="label">Total cobrado este mes</div><div class="num"><?= dinero($totalCobradoEsteMes) ?></div></div>
<div class="box"><div class="label">Total cobrado general</div><div class="num"><?= dinero($totalCobrado) ?></div></div>
<div class="box"><div class="label">Deudores <?= e($periodoActual) ?></div><div class="num"><?= e($deudoresPeriodoActual) ?></div></div>
<div class="box"><div class="label">Cheques pendientes</div><div>Vencen hoy: <strong><?= e($chequesVencenHoy) ?></strong></div><div>Vencidos: <strong><?= e($chequesVencidos) ?></strong></div></div>
</div>

<div class="card">
<h2>Inicio</h2>
<input type="text" id="homeEmpresaSearch" placeholder="Buscar rápido por razón social o CUIT">
<div id="homeEmpresaResultados" class="search-results"></div>
<div class="home-actions">
<button type="button" class="tab-jump" data-tab="buscar-empresa">Buscar empresa</button>
<button type="button" class="tab-jump" data-tab="cargar-pago">Cargar pago</button>
<button type="button" class="tab-jump" data-tab="cargar-acuerdo">Cargar acuerdo</button>
<button type="button" class="tab-jump" data-tab="informe-periodo">Informe período</button>
<button type="button" class="tab-jump" data-tab="nueva-empresa">Nueva empresa</button>
</div>
</div>
</section>

<section class="tab-panel" id="tab-nueva-empresa">
<div class="card collapsible-card" id="nueva-empresa" data-card="nueva-empresa">
<div class="card-header">
<h2><?= $editarEmpresa ? "Editar empresa" : "Nueva empresa" ?></h2>
<button type="button" class="toggle-card">Minimizar</button>
</div>
<div class="card-body">
<?php if($editarEmpresa): ?>
<p><span class="estado <?= empresaActiva($editarEmpresa) ? 'estado-ok' : 'estado-deudor' ?>"><?= empresaActiva($editarEmpresa) ? 'Activa' : 'Inactiva' ?></span></p>
<?php endif; ?>

<form method="post" id="empresaForm">
<input type="hidden" name="empresa_id" value="<?= e($editarEmpresa["id"] ?? "") ?>">
<input type="hidden" name="confirmar_empresa_parecida" id="confirmarEmpresaParecida" value="<?= $advertenciaEmpresa ? "1" : "" ?>">

<div class="grid">
<div class="campo">
<label for="empresaRazon">Razón social</label>
<input type="text" id="empresaRazon" name="razon" placeholder="Razón social" required value="<?= e($editarEmpresa["razon"] ?? "") ?>">
</div>
<div class="campo">
<label for="empresaCuit">CUIT</label>
<input type="text" id="empresaCuit" name="cuit" placeholder="CUIT (opcional)" value="<?= e($editarEmpresa["cuit"] ?? "") ?>">
</div>
</div>

<div class="campo" style="margin-top:12px">
<label for="empresaObservaciones">Observaciones</label>
<textarea id="empresaObservaciones" name="observaciones_empresa" placeholder="Observaciones empresa"><?= e($editarEmpresa["observaciones"] ?? "") ?></textarea>
</div>

<div id="empresaDuplicadosCliente"></div>

<?php if($errorEmpresa !== ""): ?>
<div class="empresa-duplicados <?= $advertenciaEmpresa ? "" : "bloqueo" ?>">
<p class="error"><?= e($errorEmpresa) ?></p>
<?php
$empresasMostradas = [];
if ($coincidenciasEmpresa["cuit"]) $empresasMostradas[] = $coincidenciasEmpresa["cuit"];
if ($coincidenciasEmpresa["exacta"] && (!$coincidenciasEmpresa["cuit"] || ($coincidenciasEmpresa["exacta"]["id"] ?? "") !== ($coincidenciasEmpresa["cuit"]["id"] ?? ""))) {
    $empresasMostradas[] = $coincidenciasEmpresa["exacta"];
}
if ($advertenciaEmpresa) $empresasMostradas = $coincidenciasEmpresa["parecidas"];
?>
<?php foreach($empresasMostradas as $coincidencia): ?>
<div class="empresa-coincidencia">
<span><strong><?= e($coincidencia["razon"] ?? "") ?></strong><?= !empty($coincidencia["cuit"]) ? " · CUIT " . e($coincidencia["cuit"]) : "" ?></span>
<button type="button" class="ver-empresa-coincidente" data-empresa="<?= e($coincidencia["id"] ?? "") ?>"><?= $advertenciaEmpresa ? "Ver ficha" : "Ver empresa existente" ?></button>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<br><br>
<button name="guardar_empresa" id="guardarEmpresa"><?= $advertenciaEmpresa ? "Guardar de todos modos" : ($editarEmpresa ? "Guardar cambios empresa" : "Guardar empresa") ?></button>
<?php if($editarEmpresa): ?>
<a class="btn-cancelar" href="index.php">Cancelar</a>
<?php endif; ?>
</form>
</div>
</div>
</section>

<section class="tab-panel" id="tab-cargar-pago">
<div class="card collapsible-card" id="cargar-pago" data-card="cargar-pago">
<div class="card-header">
<h2><?= $editarPago ? "Editar pago" : "Cargar pago" ?></h2>
<button type="button" class="toggle-card">Minimizar</button>
</div>
<div class="card-body">

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="pago_id" value="<?= e($editarPago["id"] ?? "") ?>">
<input type="hidden" name="comprobante_actual" value="<?= e($editarPago["comprobante"] ?? "") ?>">

<div class="grid">
<?php $empresaPagoSeleccionada = buscarEmpresa($empresas, $editarPago["empresa_id"] ?? ""); ?>
<div class="campo">
<label>Empresa</label>
<div class="empresa-picker" data-hidden-name="empresa_id">
<input type="text" class="empresa-picker-input" placeholder="Buscar empresa por razón social o CUIT" autocomplete="off" value="<?= e($empresaPagoSeleccionada ? (($empresaPagoSeleccionada["razon"] ?? "") . " - " . ($empresaPagoSeleccionada["cuit"] ?? "")) : "") ?>">
<input type="hidden" name="empresa_id" class="empresa-picker-hidden" required value="<?= e($editarPago["empresa_id"] ?? "") ?>">
<div class="empresa-picker-results"></div>
</div>
</div>

<div class="campo">
<label for="pagoFecha">Fecha de pago</label>
<input type="date" id="pagoFecha" name="fecha" required value="<?= e($editarPago["fecha"] ?? date("Y-m-d")) ?>">
</div>

<div class="campo">
<label for="pagoTipo">Tipo</label>
<select name="tipo" id="pagoTipo" required>
<option value="">OS / Sindicato / Mutual</option>
<?php foreach(["Obra Social","Sindicato","Mutual"] as $op): ?>
<option value="<?= e($op) ?>" <?= (($editarPago["tipo"] ?? "") === $op) ? "selected" : "" ?>><?= e($op) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="campo">
<label for="pagoForma">Forma de pago</label>
<select name="forma_pago" id="pagoForma" required>
<option value="">Forma de pago</option>
<?php foreach(["Efectivo","Transferencia","Cheque"] as $op): ?>
<option value="<?= e($op) ?>" <?= (($editarPago["forma_pago"] ?? "") === $op) ? "selected" : "" ?>><?= e($op) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="campo">
<label for="pagoTipoPago">Tipo de pago</label>
<select name="tipo_pago" id="pagoTipoPago" required>
<option value="">Seleccionar tipo de pago</option>
<?php foreach(["Pago al día","Cuota de acuerdo","Pago único / extraordinario"] as $op): ?>
<option value="<?= e($op) ?>" <?= (($editarPago["tipo_pago"] ?? "") === $op) ? "selected" : "" ?>><?= e($op) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="campo">
<label for="pagoMonto">Monto pagado</label>
<input type="number" id="pagoMonto" step="0.01" min="0" name="monto" placeholder="Monto pagado" required value="<?= e($editarPago["monto"] ?? "") ?>">
</div>

<div class="campo">
<label for="pagoPeriodo">Período</label>
<input type="text" id="pagoPeriodo" name="periodo" class="periodo-input" placeholder="MM/AA" maxlength="5" inputmode="numeric" pattern="(0[1-9]|1[0-2])\/[0-9]{2}" required value="<?= e(periodoParaInput($editarPago["periodo"] ?? "")) ?>">
</div>

<div class="campo">
<label for="pagoComprobante">Comprobante</label>
<input type="file" id="pagoComprobante" name="comprobante" accept=".pdf,.jpg,.jpeg,.png">
</div>
</div>

<?php
$chequesFormulario = chequesPago($editarPago);
$mostrarChequesFormulario = ($editarPago["forma_pago"] ?? "") === "Cheque";
?>
<div class="cheques-pago" id="bloqueChequesPago" style="<?= $mostrarChequesFormulario ? "" : "display:none" ?>">
<h3>Fechas de cobro de cheques</h3>
<div class="campo" style="max-width:240px">
<label for="cantidadCheques">Cantidad de cheques</label>
<input type="number" id="cantidadCheques" min="1" step="1" value="<?= e(max(count($chequesFormulario), 1)) ?>">
</div>
<div class="cheques-fechas" id="chequesFechas">
<?php foreach($chequesFormulario as $indiceCheque => $cheque): ?>
<div class="cheque-fecha-item">
<div class="campo">
<label>Cheque <?= e($indiceCheque + 1) ?> - Fecha de cobro</label>
<input type="date" name="cheque_fecha[]" value="<?= e($cheque["fecha_cobro"] ?? "") ?>" data-original="<?= e($cheque["fecha_cobro"] ?? "") ?>">
<input type="hidden" name="cheque_indice[]" value="<?= e($indiceCheque) ?>">
</div>
<button type="button" class="quitar-cheque" title="Quitar fecha" aria-label="Quitar fecha">&times;</button>
</div>
<?php endforeach; ?>
</div>
</div>

<div id="resumenAcuerdoPago" class="resumen-acuerdo" aria-live="polite"></div>

<p id="avisoPagoDuplicado" class="error" style="display:none"></p>

<?php if($errorPago !== ""): ?>
<p class="error"><?= e($errorPago) ?></p>
<?php endif; ?>

<?php if($editarPago && !empty($editarPago["comprobante"])): ?>
<p>
Comprobante actual:
<a class="btn-secundario" href="<?= e($editarPago["comprobante"]) ?>" target="_blank">👁️ Ver</a>
<a class="btn-secundario" href="<?= e($editarPago["comprobante"]) ?>" download>⬇️ Descargar</a>
<a class="btn-danger" href="?eliminar_comprobante=<?= e($editarPago["id"] ?? "") ?>" onclick="return confirm('¿Eliminar solo el comprobante de este pago?')" title="Eliminar comprobante" aria-label="Eliminar comprobante">🗑️</a>
</p>
<?php endif; ?>

<div class="campo" style="margin-top:12px">
<label for="pagoObservaciones">Observaciones</label>
<textarea id="pagoObservaciones" name="observaciones_pago" placeholder="Observaciones pago"><?= e($editarPago["observaciones"] ?? "") ?></textarea>
</div>

<br><br>
<button name="guardar_pago"><?= $editarPago ? "Guardar cambios pago" : "Guardar pago" ?></button>
<?php if($editarPago): ?>
<a class="btn-cancelar" href="index.php">Cancelar</a>
<?php endif; ?>
</form>
</div>
</div>
</section>

<section class="tab-panel" id="tab-cargar-acuerdo">
<div class="card collapsible-card" id="cargar-acuerdo" data-card="cargar-acuerdo">
<div class="card-header">
<h2>Cargar acuerdo</h2>
<button type="button" class="toggle-card">Minimizar</button>
</div>
<div class="card-body">

<form method="post" id="acuerdoForm">
<div class="grid">
<?php $empresaAcuerdoSeleccionada = buscarEmpresa($empresas, $acuerdoForm["empresa_id"] ?? ""); ?>
<div class="campo">
<label for="acuerdoEmpresaTexto">Empresa</label>
<div class="empresa-picker" data-hidden-name="acuerdo_empresa_id">
<input type="text" id="acuerdoEmpresaTexto" class="empresa-picker-input" placeholder="Buscar empresa por razón social o CUIT" autocomplete="off" value="<?= e($empresaAcuerdoSeleccionada ? (($empresaAcuerdoSeleccionada["razon"] ?? "") . " - " . ($empresaAcuerdoSeleccionada["cuit"] ?? "")) : "") ?>">
<input type="hidden" name="acuerdo_empresa_id" id="acuerdoEmpresa" class="empresa-picker-hidden" required value="<?= e($acuerdoForm["empresa_id"] ?? "") ?>">
<div class="empresa-picker-results"></div>
</div>
</div>

<div class="campo">
<label for="acuerdoTipo">Tipo</label>
<select name="acuerdo_tipo" id="acuerdoTipo" required>
<option value="">Tipo</option>
<?php foreach(["Obra Social","Sindicato","Mutual"] as $op): ?>
<option value="<?= e($op) ?>" <?= (($acuerdoForm["tipo"] ?? "") === $op) ? "selected" : "" ?>><?= e($op) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="campo">
<label for="acuerdoMontoTotal">Monto total de la deuda</label>
<input type="number" id="acuerdoMontoTotal" step="0.01" min="0.01" name="acuerdo_monto_total" placeholder="Monto total de la deuda" required value="<?= e($acuerdoForm["monto_total"] ?? "") ?>">
</div>

<div class="campo">
<label for="acuerdoCantidadCuotas">Cantidad total de cuotas</label>
<input type="number" id="acuerdoCantidadCuotas" name="acuerdo_cantidad_cuotas" placeholder="Cantidad total de cuotas" required value="<?= e($acuerdoForm["cantidad_cuotas"] ?? "") ?>">
</div>

<div class="campo">
<label for="acuerdoMontoCuota">Monto de cada cuota</label>
<input type="number" id="acuerdoMontoCuota" step="0.01" min="0.01" name="acuerdo_monto_cuota" placeholder="Monto de cada cuota" required value="<?= e($acuerdoForm["monto_cuota"] ?? "") ?>">
</div>

<div class="campo">
<label for="acuerdoCuotasPrevias">Cuotas previas ya pagadas</label>
<input type="number" id="acuerdoCuotasPrevias" min="0" name="acuerdo_cuotas_pagadas_previas" placeholder="Cuotas previas ya pagadas" value="<?= e($acuerdoForm["cuotas_pagadas_previas"] ?? "0") ?>">
</div>

<div class="campo">
<label for="acuerdoPeriodoDesde">Período desde</label>
<input type="text" id="acuerdoPeriodoDesde" name="acuerdo_periodo_desde" class="periodo-input" placeholder="MM/AA" maxlength="5" inputmode="numeric" pattern="(0[1-9]|1[0-2])\/[0-9]{2}" required value="<?= e(periodoParaInput($acuerdoForm["periodo_desde"] ?? "")) ?>">
</div>

<div class="campo">
<label for="acuerdoPeriodoHasta">Período hasta</label>
<input type="text" id="acuerdoPeriodoHasta" name="acuerdo_periodo_hasta" class="periodo-input" placeholder="MM/AA" maxlength="5" inputmode="numeric" pattern="(0[1-9]|1[0-2])\/[0-9]{2}" required value="<?= e(periodoParaInput($acuerdoForm["periodo_hasta"] ?? "")) ?>">
</div>
</div>

<div class="pagos-previos-selector" id="pagosPreviosSelector" aria-live="polite">
<h3>Pagos registrados que podrían corresponder a cuotas previas</h3>
<p class="sin">Seleccioná una empresa y un tipo para consultar pagos existentes.</p>
</div>

<div class="campo" style="margin-top:12px">
<label for="acuerdoObservaciones">Observaciones</label>
<textarea id="acuerdoObservaciones" name="acuerdo_observaciones" placeholder="Observaciones del acuerdo"><?= e($acuerdoForm["observaciones"] ?? "") ?></textarea>
</div>

<div class="resumen-acuerdo" id="acuerdoResumen" aria-live="polite"></div>
<div id="accionesAcuerdoExistente" style="display:none;margin-top:12px">
<a id="eliminarAcuerdoSeleccionado" class="btn-danger" href="#" onclick="return confirm('¿Eliminar este acuerdo? No se eliminarán los pagos ya cargados.')" title="Eliminar acuerdo" aria-label="Eliminar acuerdo">🗑️</a>
</div>

<?php if($errorEmpresa !== "" && isset($_POST["guardar_acuerdo"])): ?>
<p class="error"><?= e($errorEmpresa) ?></p>
<?php endif; ?>

<br><br>
<button name="guardar_acuerdo">Guardar acuerdo</button>
</form>
</div>
</div>
</section>

<section class="tab-panel" id="tab-informe-periodo">
<div class="card collapsible-card" id="informe-periodo" data-card="informe-periodo">
<div class="card-header">
<h2>Informe por período</h2>
<button type="button" class="toggle-card">Minimizar</button>
</div>
<div class="card-body">

<div class="filters">
<div class="filters-grid informe">
<input type="text" id="informePeriodo" class="periodo-input" placeholder="MM/AA" maxlength="5" inputmode="numeric">
<select id="informeTipo">
<option value="">Todos</option>
<option value="Obra Social">Obra Social</option>
<option value="Sindicato">Sindicato</option>
<option value="Mutual">Mutual</option>
</select>
<button type="button" id="generarInformePeriodo">Consultar</button>
<a class="btn-secundario" id="exportarInformePeriodo" href="?exportar=informe">Exportar Excel</a>
</div>
</div>

<div class="informe-resumen">
<div class="box"><div class="label">Período consultado</div><div class="num" id="informePeriodoConsultado">--</div></div>
<div class="box"><div class="label">Total esperado de acuerdos</div><div class="num" id="informeEsperado">$0,00</div></div>
<div class="box"><div class="label">Total cobrado del período</div><div class="num" id="informeTotal">$0,00</div></div>
<div class="box"><div class="label">Pendiente de acuerdos</div><div class="num" id="informePendiente">$0,00</div></div>
<div class="box"><div class="label">Empresas que pagaron</div><div class="num" id="informePagaron">0</div></div>
<div class="box"><div class="label">Empresas que NO pagaron</div><div class="num" id="informeNoPagaron">0</div></div>
</div>

<h3 class="mini-title">Empresas que pagaron</h3>
<table>
<thead>
<tr>
<th>Razón social</th>
<th>CUIT</th>
<th>Tipo</th>
<th>Tipo de pago</th>
<th>Cuota esperada</th>
<th>Monto pagado</th>
<th>Estado</th>
<th>Fecha de pago</th>
<th>Comprobante</th>
<th>Acciones</th>
</tr>
</thead>
<tbody id="informePagaronBody">
<tr><td colspan="10" class="sin">Ingresá un período para consultar.</td></tr>
</tbody>
</table>

<h3 class="mini-title">Empresas que NO pagaron</h3>
<table>
<thead>
<tr>
<th>Razón social</th>
<th>CUIT</th>
<th>Tipo adeudado</th>
<th>Tipo de pago</th>
<th>Cuota esperada</th>
<th>Período acuerdo</th>
<th>Último pago registrado</th>
<th>Estado</th>
<th>Acciones</th>
</tr>
</thead>
<tbody id="informeNoPagaronBody">
<tr><td colspan="9" class="sin">Ingresá un período para consultar.</td></tr>
</tbody>
</table>
</div>
</div>
</section>

<section class="tab-panel" id="tab-buscar-empresa">
<div class="card collapsible-card" id="empresas" data-card="empresas">
<div class="card-header">
<h2>Buscar empresa</h2>
<button type="button" class="toggle-card">Minimizar</button>
</div>
<div class="card-body">

<div class="filters">
<input type="text" id="buscadorFichaEmpresa" placeholder="Buscar por razón social o CUIT">
<div id="resultadosFichaEmpresa" class="search-results"></div>
</div>
<div id="fichaEmpresa" class="empresa-ficha">
<span class="sin">Buscá y seleccioná una empresa para ver su ficha.</span>
</div>

<div class="filters">
<div class="filters-grid empresas">
<select id="filtroEmpresaCategoria">
<option value="">Todas</option>
<option value="os">Obra Social</option>
<option value="sindicato">Sindicato</option>
<option value="mutual">Mutual</option>
</select>
<select id="filtroEmpresaPlan">
<option value="">Plan: Todos</option>
<option value="acuerdo">Acuerdo</option>
<option value="sin-acuerdo">Sin acuerdo</option>
</select>
<input type="text" id="filtroEmpresaTexto" placeholder="Buscar por razón social o CUIT">
<select id="filtroEmpresaEstado">
<option value="">Estado deuda: Todas</option>
<option value="deuda">Con deuda</option>
<option value="cancelada">Canceladas</option>
</select>
<select id="filtroEmpresaActiva">
<option value="activas">Activas</option>
<option value="inactivas">Inactivas</option>
<option value="todas">Todas</option>
</select>
<button type="button" id="limpiarFiltrosEmpresas">Limpiar filtros</button>
</div>
</div>

<table>
<thead>
<tr>
<th>Razón Social</th>
<th>CUIT</th>
<th>Estado</th>
<th>Monto total</th>
<th>Plan</th>
<th>Cuotas</th>
<th>Período acuerdo</th>
<th>Deuda OS</th>
<th>Cobrado OS</th>
<th>Saldo OS</th>
<th>Deuda Sindicato</th>
<th>Cobrado Sindicato</th>
<th>Saldo Sindicato</th>
<th>Deuda Mutual</th>
<th>Cobrado Mutual</th>
<th>Saldo Mutual</th>
<th>Acciones</th>
</tr>
</thead>
<tbody>
<?php if(empty($empresas)): ?>
<tr><td colspan="17" class="sin">Todavía no hay empresas cargadas.</td></tr>
<?php endif; ?>

<?php foreach($empresas as $emp):
$activaEmpresa = empresaActiva($emp);
$resumenOS = resumenFinancieroEmpresaTipo($emp, "Obra Social", $pagos);
$resumenSind = resumenFinancieroEmpresaTipo($emp, "Sindicato", $pagos);
$resumenMutual = resumenFinancieroEmpresaTipo($emp, "Mutual", $pagos);
$deudaOS = $resumenOS["deuda"];
$deudaSind = $resumenSind["deuda"];
$deudaMutual = $resumenMutual["deuda"];
$acuerdoTabla = acuerdoDefault();
foreach (["Obra Social","Sindicato","Mutual"] as $tipoAcuerdoTabla) {
    $tmpAcuerdo = acuerdoEmpresa($emp, $tipoAcuerdoTabla);
    if (
        intval($tmpAcuerdo["cantidad_cuotas"] ?? 0) >= 2 &&
        floatval($tmpAcuerdo["monto_total"] ?? 0) > 0 &&
        floatval($tmpAcuerdo["monto_cuota"] ?? 0) > 0
    ) {
        $acuerdoTabla = $tmpAcuerdo;
        break;
    }
}
$montoTotalEmpresa = floatval($acuerdoTabla["monto_total"] ?? 0);
$cantidadCuotasEmpresa = max(intval($acuerdoTabla["cantidad_cuotas"] ?? 1), 1);
$montoCuotaEmpresa = floatval($acuerdoTabla["monto_cuota"] ?? 0);
$periodoDesdeEmpresa = periodoParaInput($acuerdoTabla["periodo_desde"] ?? "");
$periodoHastaEmpresa = periodoParaInput($acuerdoTabla["periodo_hasta"] ?? "");
$esAcuerdoEmpresa = $cantidadCuotasEmpresa > 1;
$planEmpresa = $esAcuerdoEmpresa ? "Acuerdo" : "Sin acuerdo";
$planFiltroEmpresa = $esAcuerdoEmpresa ? "acuerdo" : "sin-acuerdo";
$cuotasEmpresa = $esAcuerdoEmpresa ? ($cantidadCuotasEmpresa . " x " . dinero($montoCuotaEmpresa)) : "-";
$periodoAcuerdoEmpresa = $esAcuerdoEmpresa
    ? trim($periodoDesdeEmpresa . (($periodoDesdeEmpresa !== "" || $periodoHastaEmpresa !== "") ? " a " : "") . $periodoHastaEmpresa)
    : "";

$pagadoOS = $resumenOS["cobrado"];
$pagadoSind = $resumenSind["cobrado"];
$pagadoMutual = $resumenMutual["cobrado"];

$saldoOS = $resumenOS["saldo"];
$saldoSind = $resumenSind["saldo"];
$saldoMutual = $resumenMutual["saldo"];

$tieneDeudaCargada = ($resumenOS["tiene_acuerdo"] || $resumenSind["tiene_acuerdo"] || $resumenMutual["tiene_acuerdo"]);
$tieneSaldoReal = ($saldoOS > 0 || $saldoSind > 0 || $saldoMutual > 0);
$estadoEmpresa = $tieneSaldoReal ? "deuda" : ($tieneDeudaCargada ? "cancelada" : "");

$categoriaOS = ($deudaOS > 0 || $pagadoOS > 0) ? "1" : "0";
$categoriaSind = ($deudaSind > 0 || $pagadoSind > 0) ? "1" : "0";
$categoriaMutual = ($deudaMutual > 0 || $pagadoMutual > 0) ? "1" : "0";
?>
<tr class="fila-empresa" data-busqueda="<?= e(($emp["razon"] ?? "") . " " . ($emp["cuit"] ?? "")) ?>" data-estado="<?= e($estadoEmpresa) ?>" data-activa="<?= $activaEmpresa ? '1' : '0' ?>" data-plan="<?= e($planFiltroEmpresa) ?>" data-os="<?= e($categoriaOS) ?>" data-sindicato="<?= e($categoriaSind) ?>" data-mutual="<?= e($categoriaMutual) ?>">
<td><?= e($emp["razon"]) ?></td>
<td><?= e($emp["cuit"]) ?></td>
<td><span class="estado <?= $activaEmpresa ? 'estado-ok' : 'estado-deudor' ?>"><?= $activaEmpresa ? 'Activa' : 'Inactiva' ?></span></td>
<td><?= dinero($montoTotalEmpresa) ?></td>
<td><?= e($planEmpresa) ?></td>
<td><?= e($cuotasEmpresa) ?></td>
<td><?= e($periodoAcuerdoEmpresa) ?></td>
<td><?= dinero($deudaOS) ?></td>
<td class="saldo-ok"><?= dinero($pagadoOS) ?></td>
<td class="<?= $saldoOS <= 0 ? 'saldo-ok' : 'saldo-debe' ?>"><?= dinero($saldoOS) ?></td>
<td><?= dinero($deudaSind) ?></td>
<td class="saldo-ok"><?= dinero($pagadoSind) ?></td>
<td class="<?= $saldoSind <= 0 ? 'saldo-ok' : 'saldo-debe' ?>"><?= dinero($saldoSind) ?></td>
<td><?= dinero($deudaMutual) ?></td>
<td class="saldo-ok"><?= dinero($pagadoMutual) ?></td>
<td class="<?= $saldoMutual <= 0 ? 'saldo-ok' : 'saldo-debe' ?>"><?= dinero($saldoMutual) ?></td>
<td class="acciones">
<a href="?editar_empresa=<?= e($emp["id"]) ?>" title="Editar empresa">✏️</a>
<?php if($activaEmpresa): ?>
<a class="btn-danger" href="?eliminar_empresa=<?= e($emp["id"]) ?>" onclick="return confirm('La empresa quedará inactiva y sus pagos se conservarán. ¿Dar de baja empresa?')" title="Dar de baja empresa" aria-label="Dar de baja empresa">🗑️</a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</section>

<section class="tab-panel" id="tab-pagos">
<div class="card collapsible-card" id="pagos" data-card="pagos">
<div class="card-header">
<h2>Pagos registrados</h2>
<a class="btn-secundario" href="?exportar=pagos">Exportar Excel</a>
<button type="button" class="toggle-card">Minimizar</button>
</div>
<div class="card-body">

<div class="filters">
<div class="filters-grid">
<input type="text" id="filtroPagoTexto" placeholder="Buscar por empresa o CUIT">
<select id="filtroPagoTipo">
<option value="">Todos</option>
<option value="Obra Social">Obra Social</option>
<option value="Sindicato">Sindicato</option>
<option value="Mutual">Mutual</option>
</select>
<select id="filtroPagoForma">
<option value="">Todas</option>
<option value="Efectivo">Efectivo</option>
<option value="Transferencia">Transferencia</option>
<option value="Cheque">Cheque</option>
</select>
<input type="text" id="filtroPagoPeriodo" class="periodo-input" placeholder="MM/AA" maxlength="5" inputmode="numeric">
<button type="button" id="limpiarFiltrosPagos">Limpiar filtros</button>
</div>
</div>

<table>
<thead>
<tr>
<th>Fecha</th>
<th>Empresa</th>
<th>CUIT</th>
<th>Tipo</th>
<th>Tipo de pago</th>
<th>Forma</th>
<th>Período</th>
<th>Monto</th>
<th>Comprobante</th>
<th>Acciones</th>
</tr>
</thead>
<tbody>
<?php if(empty($pagos)): ?>
<tr><td colspan="10" class="sin">Todavía no hay pagos registrados.</td></tr>
<?php endif; ?>

<?php foreach(array_reverse($pagos) as $p):
$emp = buscarEmpresa($empresas, $p["empresa_id"] ?? "");
$periodoPago = periodoParaInput($p["periodo"] ?? "");
$cantidadChequesPago = count(chequesPago($p));
$esPagoPrevioVinculado = $emp && pagoVinculadoComoPrevio($p, $emp);
?>
<tr class="fila-pago" data-busqueda="<?= e(($emp["razon"] ?? "Empresa eliminada") . " " . ($emp["cuit"] ?? "")) ?>" data-tipo="<?= e($p["tipo"] ?? "") ?>" data-forma="<?= e($p["forma_pago"] ?? "") ?>" data-periodo="<?= e($periodoPago) ?>">
<td><?= e($p["fecha"] ?? "") ?></td>
<td><?= e($emp["razon"] ?? "Empresa eliminada") ?></td>
<td><?= e($emp["cuit"] ?? "") ?></td>
<td><span class="badge"><?= e($p["tipo"] ?? "") ?></span><?php if($esPagoPrevioVinculado): ?><br><span class="estado estado-previa">Aplicado como cuota previa del acuerdo</span><?php endif; ?></td>
<td><?= e(tipoPagoCompatible($p, $empresas)) ?></td>
<td><?= e((($p["forma_pago"] ?? "") ?: ($cantidadChequesPago > 0 ? "Cheque" : "")) . ($cantidadChequesPago > 0 ? " (" . $cantidadChequesPago . ")" : "")) ?></td>
<td><?= e($periodoPago) ?></td>
<td><?= dinero($p["monto"] ?? 0) ?></td>
<td>
<?php if(!empty($p["comprobante"])): ?>
<a href="<?= e($p["comprobante"]) ?>" target="_blank">👁️</a>
<a href="<?= e($p["comprobante"]) ?>" download>⬇️</a>
<?php else: ?>
<span class="sin">Sin comprobante</span>
<?php endif; ?>
</td>
<td class="acciones">
<a href="?editar_pago=<?= e($p["id"]) ?>" title="Editar pago">✏️</a>
<a class="btn-danger" href="?eliminar_pago=<?= e($p["id"]) ?>" onclick="return confirm('¿Eliminar este pago? Esta acción no elimina la empresa.')" title="Eliminar pago" aria-label="Eliminar pago">🗑️</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</section>

<?php if ($esAdmin): ?>
<section class="tab-panel" id="tab-auditoria">
<div class="card collapsible-card" id="auditoria" data-card="auditoria">
<div class="card-header">
<h2>Auditoría</h2>
<button type="button" class="toggle-card">Minimizar</button>
</div>
<div class="card-body">
<div class="filters">
<div class="filters-grid auditoria">
<select id="filtroAuditoriaUsuario">
<option value="">Todos</option>
<option value="ADMIN">ADMIN</option>
<option value="AGUERO">AGUERO</option>
<option value="MONTELEONE">MONTELEONE</option>
<option value="ESCUDERO">ESCUDERO</option>
<option value="SISTEMA">SISTEMA</option>
</select>
<select id="filtroAuditoriaAccion">
<option value="">Todas</option>
<option value="crear_empresa">Crear empresa</option>
<option value="editar_empresa">Editar empresa</option>
<option value="dar_de_baja_empresa">Dar de baja empresa</option>
<option value="crear_acuerdo">Crear acuerdo</option>
<option value="editar_acuerdo">Editar acuerdo</option>
<option value="eliminar_acuerdo">Eliminar acuerdo</option>
<option value="crear_pago">Crear pago</option>
<option value="editar_pago">Editar pago</option>
<option value="crear_pago_cheques">Crear pago con cheques</option>
<option value="editar_fechas_cheques">Editar fechas de cheques</option>
<option value="marcar_cheque_cobrado">Marcar cheque cobrado</option>
<option value="eliminar_pago">Eliminar pago</option>
<option value="descargar_backup">Descargar backup</option>
</select>
<input type="text" id="filtroAuditoriaTexto" placeholder="Buscar en detalle">
<input type="date" id="filtroAuditoriaDesde">
<input type="date" id="filtroAuditoriaHasta">
<button type="button" id="limpiarFiltrosAuditoria">Limpiar filtros</button>
<a class="btn-secundario" id="exportarAuditoriaCsv" href="?exportar=auditoria">Exportar auditoría CSV</a>
</div>
</div>
<table>
<thead>
<tr>
<th>Fecha y hora</th>
<th>Usuario</th>
<th>Acción</th>
<th>Detalle</th>
</tr>
</thead>
<tbody>
<?php $auditoriaOrdenada = array_reverse($auditoria); ?>
<?php if(empty($auditoriaOrdenada)): ?>
<tr><td colspan="4" class="sin">Todavía no hay movimientos registrados.</td></tr>
<?php endif; ?>
<?php foreach(array_slice($auditoriaOrdenada, 0, 200) as $movimiento): ?>
<tr class="fila-auditoria" data-usuario="<?= e($movimiento["usuario"] ?? "") ?>" data-accion="<?= e($movimiento["accion"] ?? "") ?>" data-fecha="<?= e(substr($movimiento["fecha"] ?? "", 0, 10)) ?>" data-detalle="<?= e($movimiento["detalle"] ?? "") ?>">
<td><?= e($movimiento["fecha"] ?? "") ?></td>
<td><?= e($movimiento["usuario"] ?? "") ?></td>
<td><span class="badge"><?= e($movimiento["accion"] ?? "") ?></span></td>
<td><?= e($movimiento["detalle"] ?? "") ?></td>
</tr>
<?php endforeach; ?>
<tr id="auditoriaSinResultados" class="fila-oculta"><td colspan="4" class="sin">No se encontraron movimientos con esos filtros.</td></tr>
</tbody>
</table>
</div>
</div>
</section>
<?php endif; ?>

</main>
<script>
const empresasData = <?= json_encode($empresas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const pagosData = <?= json_encode($pagos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const pagosPreviosFormData = <?= json_encode(array_values($acuerdoForm["pagos_previos_ids"] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const tiposInforme = ["Obra Social", "Sindicato", "Mutual"];
const tabInicial = <?= json_encode($tabInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

const notificacionesCheques = document.getElementById("notificacionesCheques");
const notificacionesToggle = document.getElementById("notificacionesToggle");
notificacionesToggle?.addEventListener("click", () => {
    const abierta = notificacionesCheques?.classList.toggle("abierta") || false;
    notificacionesToggle.setAttribute("aria-expanded", abierta ? "true" : "false");
});
document.addEventListener("click", (event) => {
    if (notificacionesCheques && !notificacionesCheques.contains(event.target)) {
        notificacionesCheques.classList.remove("abierta");
        notificacionesToggle?.setAttribute("aria-expanded", "false");
    }
});

function formatearPeriodo(valor) {
    const numeros = (valor || "").replace(/\D/g, "").slice(0, 4);
    if (numeros.length <= 2) return numeros;
    return numeros.slice(0, 2) + "/" + numeros.slice(2);
}

function periodoValidoCliente(valor) {
    return /^(0[1-9]|1[0-2])\/\d{2}$/.test(valor || "");
}

document.querySelectorAll(".periodo-input").forEach((input) => {
    input.addEventListener("input", () => {
        input.value = formatearPeriodo(input.value);
    });
});

const acuerdoFormEl = document.getElementById("acuerdoForm");
if (acuerdoFormEl) {
    const pagosPreviosSeleccionados = () => Array.from(
        acuerdoFormEl.querySelectorAll('input[name="pagos_previos_ids[]"]:checked')
    ).map((input) => input.value);

    const renderPagosPreviosAcuerdo = (seleccionForzada = null) => {
        const contenedor = document.getElementById("pagosPreviosSelector");
        if (!contenedor) return;
        const empresaId = acuerdoFormEl.querySelector('input[name="acuerdo_empresa_id"]')?.value || "";
        const tipo = acuerdoFormEl.querySelector('select[name="acuerdo_tipo"]')?.value || "";
        const desde = periodoAMesIndice(acuerdoFormEl.querySelector('input[name="acuerdo_periodo_desde"]')?.value || "");
        const hasta = periodoAMesIndice(acuerdoFormEl.querySelector('input[name="acuerdo_periodo_hasta"]')?.value || "");
        const seleccion = new Set(seleccionForzada === null ? pagosPreviosSeleccionados() : seleccionForzada);

        if (!empresaId || !tipo) {
            contenedor.innerHTML = '<h3>Pagos registrados que podrían corresponder a cuotas previas</h3><p class="sin">Seleccioná una empresa y un tipo para consultar pagos existentes.</p>';
            return;
        }

        const candidatos = pagosData
            .filter((pago) => (pago.empresa_id || "") === empresaId && (pago.tipo || "") === tipo && (pago.id || ""))
            .map((pago) => {
                const indice = periodoAMesIndice(pago.periodo || "");
                return { pago, dentro: desde !== null && hasta !== null && indice !== null && indice >= desde && indice <= hasta };
            })
            .sort((a, b) => Number(b.dentro) - Number(a.dentro) || periodoNormalizado(a.pago.periodo || "").localeCompare(periodoNormalizado(b.pago.periodo || "")));

        if (!candidatos.length) {
            contenedor.innerHTML = '<h3>Pagos registrados que podrían corresponder a cuotas previas</h3><p class="sin">No hay pagos registrados para esta empresa y tipo.</p>';
            return;
        }

        contenedor.innerHTML = `<h3>Pagos registrados que podrían corresponder a cuotas previas</h3>
<p>Marcá los pagos que ya forman parte de las cuotas previas declaradas.</p>
<table><thead><tr><th></th><th>Fecha</th><th>Período</th><th>Monto</th><th>Forma de pago</th><th>Observaciones</th></tr></thead><tbody>
${candidatos.map(({ pago, dentro }) => `<tr class="${dentro ? "" : "pago-fuera-periodo"}">
<td><input type="checkbox" name="pagos_previos_ids[]" value="${escapeHtml(pago.id || "")}" ${seleccion.has(pago.id || "") ? "checked" : ""}></td>
<td>${escapeHtml(pago.fecha || "")}</td>
<td>${escapeHtml(periodoNormalizado(pago.periodo || ""))}${dentro ? "" : '<div class="advertencia">Fuera del período del acuerdo</div>'}</td>
<td>${dineroCliente(pago.monto)}</td>
<td>${escapeHtml(pago.forma_pago || "")}</td>
<td>${escapeHtml(pago.observaciones || "")}</td>
</tr>`).join("")}
</tbody></table>`;

        contenedor.querySelectorAll('input[name="pagos_previos_ids[]"]').forEach((checkbox) => {
            checkbox.addEventListener("change", () => {
                const previas = Math.max(Number(acuerdoFormEl.querySelector('input[name="acuerdo_cuotas_pagadas_previas"]')?.value || 0), 0);
                if (pagosPreviosSeleccionados().length > previas) {
                    checkbox.checked = false;
                    alert("Los pagos previos vinculados no pueden superar las cuotas previas declaradas.");
                }
                acuerdoFormEl.actualizarResumen?.();
            });
        });
    };

    acuerdoFormEl.renderPagosPrevios = renderPagosPreviosAcuerdo;

    const actualizarResumenAcuerdo = () => {
        const cantidadInput = acuerdoFormEl.querySelector('input[name="acuerdo_cantidad_cuotas"]');
        const previasInput = acuerdoFormEl.querySelector('input[name="acuerdo_cuotas_pagadas_previas"]');
        const montoCuotaInput = acuerdoFormEl.querySelector('input[name="acuerdo_monto_cuota"]');
        const periodoDesdeInput = acuerdoFormEl.querySelector('input[name="acuerdo_periodo_desde"]');
        const periodoHastaInput = acuerdoFormEl.querySelector('input[name="acuerdo_periodo_hasta"]');
        const cantidadIngresada = Number(cantidadInput?.value || 0);
        const cantidad = Math.max(cantidadIngresada, 0);
        const previas = Math.min(Math.max(Number(previasInput?.value || 0), 0), Math.max(cantidad - 1, 0));
        const vinculadas = pagosPreviosSeleccionados().length;
        const previasSinRegistro = Math.max(previas - vinculadas, 0);
        const pendientes = Math.max(cantidad - previas, 0);
        const montoTotal = Number(acuerdoFormEl.querySelector('input[name="acuerdo_monto_total"]')?.value || 0);
        const montoCuota = Number(montoCuotaInput?.value || 0);
        const desde = periodoDesdeInput?.value || "--";
        const hasta = periodoHastaInput?.value || "--";

        if (previasInput) previasInput.max = String(Math.max(cantidad - 1, 0));

        const resumen = document.getElementById("acuerdoResumen");
        if (resumen) {
            resumen.innerHTML = `<h3>Resumen automático</h3><div class="resumen-acuerdo-grid">
                <div class="resumen-acuerdo-item"><strong>Plan</strong>${cantidadIngresada ? `Acuerdo de ${cantidad} cuotas` : "Completá la cantidad de cuotas"}</div>
                <div class="resumen-acuerdo-item"><strong>Monto total</strong>${dineroCliente(montoTotal)}</div>
                <div class="resumen-acuerdo-item"><strong>Monto cuota</strong>${dineroCliente(montoCuota)}</div>
                <div class="resumen-acuerdo-item"><strong>Cuotas previas pagadas</strong>${previas}</div>
                <div class="resumen-acuerdo-item"><strong>Pagos previos vinculados</strong>${vinculadas}</div>
                <div class="resumen-acuerdo-item"><strong>Previas estimadas sin registro</strong>${previasSinRegistro}</div>
                <div class="resumen-acuerdo-item"><strong>Cuotas pendientes</strong>${pendientes}</div>
                <div class="resumen-acuerdo-item"><strong>Período</strong>${escapeHtml(desde)} a ${escapeHtml(hasta)}</div>
            </div>`;
        }
    };

    acuerdoFormEl.addEventListener("input", actualizarResumenAcuerdo);
    acuerdoFormEl.addEventListener("change", actualizarResumenAcuerdo);
    acuerdoFormEl.actualizarResumen = actualizarResumenAcuerdo;
    renderPagosPreviosAcuerdo(pagosPreviosFormData);
    actualizarResumenAcuerdo();

    ["acuerdo_periodo_desde", "acuerdo_periodo_hasta"].forEach((nombre) => {
        acuerdoFormEl.querySelector(`[name="${nombre}"]`)?.addEventListener("change", () => renderPagosPreviosAcuerdo());
    });

    acuerdoFormEl.addEventListener("submit", (event) => {
        const empresaId = acuerdoFormEl.querySelector('input[name="acuerdo_empresa_id"]')?.value || "";
        const montoTotal = Number(acuerdoFormEl.querySelector('input[name="acuerdo_monto_total"]')?.value || 0);
        const cantidadCuotas = Number(acuerdoFormEl.querySelector('input[name="acuerdo_cantidad_cuotas"]')?.value || 0);
        const montoCuota = Number(acuerdoFormEl.querySelector('input[name="acuerdo_monto_cuota"]')?.value || 0);
        const cuotasPrevias = Number(acuerdoFormEl.querySelector('input[name="acuerdo_cuotas_pagadas_previas"]')?.value || 0);
        const pagosPrevios = pagosPreviosSeleccionados();
        const periodoDesde = acuerdoFormEl.querySelector('input[name="acuerdo_periodo_desde"]');
        const periodoHasta = acuerdoFormEl.querySelector('input[name="acuerdo_periodo_hasta"]');

        if (!empresaId) {
            event.preventDefault();
            alert("Seleccioná una empresa desde el buscador.");
            acuerdoFormEl.querySelector(".empresa-picker-input")?.focus();
            return;
        }

        if (montoTotal <= 0) {
            event.preventDefault();
            alert("El monto total de la deuda debe ser mayor a 0.");
            acuerdoFormEl.querySelector('input[name="acuerdo_monto_total"]')?.focus();
            return;
        }

        if (cantidadCuotas < 2) {
            event.preventDefault();
            alert("Para pago único usá la pestaña Cargar pago. Un acuerdo debe tener 2 cuotas o más.");
            acuerdoFormEl.querySelector('input[name="acuerdo_cantidad_cuotas"]')?.focus();
            return;
        }

        if (cuotasPrevias < 0 || cuotasPrevias >= cantidadCuotas) {
            event.preventDefault();
            alert("Las cuotas previas ya pagadas deben ser mayores o iguales a 0 y menores que la cantidad total de cuotas.");
            acuerdoFormEl.querySelector('input[name="acuerdo_cuotas_pagadas_previas"]')?.focus();
            return;
        }

        if (pagosPrevios.length > cuotasPrevias) {
            event.preventDefault();
            alert("Los pagos previos vinculados no pueden superar las cuotas previas declaradas.");
            return;
        }

        if (montoCuota <= 0) {
            event.preventDefault();
            alert("El monto de cada cuota debe ser mayor a 0.");
            acuerdoFormEl.querySelector('input[name="acuerdo_monto_cuota"]')?.focus();
            return;
        }

        if (!periodoDesde?.value) {
            event.preventDefault();
            alert("El período es obligatorio.");
            periodoDesde?.focus();
            return;
        }

        if (!periodoHasta?.value) {
            event.preventDefault();
            alert("El período hasta es obligatorio.");
            periodoHasta?.focus();
            return;
        }

        if (periodoDesde?.value && !periodoValidoCliente(periodoDesde.value)) {
            event.preventDefault();
            alert("El período desde debe tener formato MM/AA.");
            periodoDesde.focus();
            return;
        }

        if (periodoHasta?.value && !periodoValidoCliente(periodoHasta.value)) {
            event.preventDefault();
            alert("El período hasta debe tener formato MM/AA.");
            periodoHasta.focus();
            return;
        }

        if (periodoAMesIndice(periodoHasta?.value) < periodoAMesIndice(periodoDesde?.value)) {
            event.preventDefault();
            alert("El período hasta no puede ser anterior al período desde.");
            periodoHasta?.focus();
        }
    });
}

const mensajePagoDuplicado = "Ya existe un pago cargado para esta empresa, este tipo, este período y este tipo de pago.";

function buscarPagoDuplicado(empresaId, tipo, periodo, tipoPago, pagoIdIgnorado = "") {
    const periodoBuscado = periodoNormalizado(periodo);
    if (!empresaId || !tipo || !tipoPago || !periodoValidoCliente(periodoBuscado)) return null;

    return pagosData.find((pago) =>
        (pago.id || "") !== pagoIdIgnorado &&
        (pago.empresa_id || "") === empresaId &&
        (pago.tipo || "") === tipo &&
        periodoNormalizado(pago.periodo || "") === periodoBuscado &&
        tipoPagoCompatibleCliente(pago) === tipoPago
    ) || null;
}

const pagoForm = document.querySelector('form[enctype="multipart/form-data"]');
if (pagoForm) {
    const empresaIdInput = pagoForm.querySelector('input[name="empresa_id"]');
    const tipoInput = pagoForm.querySelector('select[name="tipo"]');
    const tipoPagoInput = pagoForm.querySelector('select[name="tipo_pago"]');
    const periodoInput = pagoForm.querySelector('input[name="periodo"]');
    const pagoIdInput = pagoForm.querySelector('input[name="pago_id"]');
    const montoInput = pagoForm.querySelector('input[name="monto"]');
    const formaPagoInput = pagoForm.querySelector('select[name="forma_pago"]');
    const bloqueCheques = document.getElementById("bloqueChequesPago");
    const cantidadChequesInput = document.getElementById("cantidadCheques");
    const chequesFechas = document.getElementById("chequesFechas");
    const avisoDuplicado = document.getElementById("avisoPagoDuplicado");
    const resumenAcuerdo = document.getElementById("resumenAcuerdoPago");
    let claveDuplicadaAvisada = "";

    const crearFilaCheque = () => {
        const fila = document.createElement("div");
        fila.className = "cheque-fecha-item";
        fila.innerHTML = `<div class="campo">
            <label>Fecha de cobro</label>
            <input type="date" name="cheque_fecha[]" data-original="">
            <input type="hidden" name="cheque_indice[]" value="">
        </div>
        <button type="button" class="quitar-cheque" title="Quitar fecha" aria-label="Quitar fecha">&times;</button>`;
        return fila;
    };

    const actualizarFilasCheques = () => {
        if (!chequesFechas) return;
        const filas = Array.from(chequesFechas.querySelectorAll(".cheque-fecha-item"));
        filas.forEach((fila, indice) => {
            const label = fila.querySelector("label");
            const fecha = fila.querySelector('input[name="cheque_fecha[]"]');
            if (label) label.textContent = `Cheque ${indice + 1} - Fecha de cobro`;
            if (fecha) fecha.required = formaPagoInput?.value === "Cheque";
        });
        if (cantidadChequesInput) cantidadChequesInput.value = String(filas.length);
    };

    const ajustarCantidadCheques = () => {
        if (!chequesFechas || !cantidadChequesInput) return;
        const cantidad = Math.max(Number.parseInt(cantidadChequesInput.value || "1", 10) || 1, 1);
        let filas = Array.from(chequesFechas.querySelectorAll(".cheque-fecha-item"));
        while (filas.length < cantidad) {
            chequesFechas.appendChild(crearFilaCheque());
            filas = Array.from(chequesFechas.querySelectorAll(".cheque-fecha-item"));
        }
        while (filas.length > cantidad) {
            filas.pop()?.remove();
        }
        actualizarFilasCheques();
    };

    const actualizarVisibilidadCheques = () => {
        const esCheque = formaPagoInput?.value === "Cheque";
        if (bloqueCheques) bloqueCheques.style.display = esCheque ? "" : "none";
        if (esCheque && chequesFechas && !chequesFechas.querySelector(".cheque-fecha-item")) {
            ajustarCantidadCheques();
        }
        chequesFechas?.querySelectorAll('input[name="cheque_fecha[]"]').forEach((input) => {
            input.required = esCheque;
        });
    };

    cantidadChequesInput?.addEventListener("change", ajustarCantidadCheques);
    formaPagoInput?.addEventListener("change", actualizarVisibilidadCheques);
    chequesFechas?.addEventListener("click", (event) => {
        const boton = event.target.closest(".quitar-cheque");
        if (!boton) return;
        boton.closest(".cheque-fecha-item")?.remove();
        if (!chequesFechas.querySelector(".cheque-fecha-item")) {
            chequesFechas.appendChild(crearFilaCheque());
        }
        actualizarFilasCheques();
    });
    if (chequesFechas && !chequesFechas.querySelector(".cheque-fecha-item")) ajustarCantidadCheques();
    actualizarFilasCheques();
    actualizarVisibilidadCheques();

    const renderResumenAcuerdoPago = () => {
        const empresaId = empresaIdInput?.value || "";
        const tipo = tipoInput?.value || "";
        const tipoPago = tipoPagoInput?.value || "";
        const empresa = obtenerEmpresa(empresaId);

        if (!resumenAcuerdo) return;
        if (!tipoPago) {
            resumenAcuerdo.innerHTML = '<p class="sin">Seleccioná el tipo de pago.</p>';
            return;
        }
        if (tipoPago === "Pago al día") {
            resumenAcuerdo.innerHTML = '<p><strong>Pago al día.</strong> Corresponde al período corriente y no requiere un acuerdo.</p>';
            return;
        }
        if (tipoPago === "Pago único / extraordinario") {
            resumenAcuerdo.innerHTML = '<p><strong>Pago único / extraordinario.</strong> Es un pago suelto y no requiere un acuerdo.</p>';
            return;
        }
        if (!empresaId || !tipo || !empresa) {
            resumenAcuerdo.innerHTML = '<p class="sin">Seleccioná una empresa y un tipo para consultar el acuerdo.</p>';
            return;
        }

        if (!tieneDatosAcuerdo(empresa, tipo)) {
            resumenAcuerdo.innerHTML = '<p><strong>No hay acuerdo cargado para esta empresa y este tipo. Podés cargar un pago único normal.</strong></p>';
            return;
        }

        const acuerdo = acuerdoEmpresaTipo(empresa, tipo);
        const cantidad = Math.max(Number(acuerdo.cantidad_cuotas || 2), 2);
        const previas = Math.min(Math.max(Number(acuerdo.cuotas_pagadas_previas || 0), 0), cantidad - 1);
        const desde = periodoNormalizado(acuerdo.periodo_desde || "");
        const hasta = periodoNormalizado(acuerdo.periodo_hasta || "");
        const indiceDesde = periodoAMesIndice(desde);
        const periodos = indiceDesde === null
            ? []
            : Array.from({ length: cantidad }, (_, indice) => periodoDesdeIndice(indiceDesde + indice));
        const pagosAcuerdo = pagosData.filter((pago) =>
            (pago.empresa_id || "") === empresaId &&
            (pago.tipo || "") === tipo &&
            esCuotaAcuerdoPago(pago, empresa, tipo) &&
            periodos.includes(periodoNormalizado(pago.periodo || ""))
        );
        const pagosSistema = periodos.filter((periodo, indice) =>
            indice >= previas && pagosAcuerdo.some((pago) =>
                periodoNormalizado(pago.periodo || "") === periodo &&
                !pagoVinculadoComoPrevioCliente(pago, empresa)
            )
        ).length;
        const pendientes = Math.max(cantidad - previas - pagosSistema, 0);

        const filas = periodos.map((periodo, indice) => {
            const pago = pagosAcuerdo.find((item) => periodoNormalizado(item.periodo || "") === periodo);
            if (pago && pagoVinculadoComoPrevioCliente(pago, empresa)) {
                return `<tr><td>${escapeHtml(periodo)}</td><td><span class="estado estado-previa">Pagada previa registrada</span></td><td><a class="btn-secundario" href="?editar_pago=${encodeURIComponent(pago.id || "")}#cargar-pago">Ver pago</a></td></tr>`;
            }
            if (indice < previas) {
                return `<tr><td>${escapeHtml(periodo)}</td><td><span class="estado estado-previa">Pagada previa</span></td><td>-</td></tr>`;
            }
            if (pago) {
                return `<tr><td>${escapeHtml(periodo)}</td><td><span class="estado estado-ok">Pagada en sistema</span></td><td><a class="btn-secundario" href="?editar_pago=${encodeURIComponent(pago.id || "")}#cargar-pago">Ver pago</a></td></tr>`;
            }
            return `<tr><td>${escapeHtml(periodo)}</td><td><span class="estado estado-deudor">Pendiente</span></td><td><button type="button" class="btn-small seleccionar-periodo-acuerdo" data-periodo="${escapeHtml(periodo)}">Seleccionar período</button></td></tr>`;
        }).join("");

        resumenAcuerdo.innerHTML = `
            <h3>Acuerdo ${escapeHtml(tipo)}</h3>
            <div class="resumen-acuerdo-grid">
                <div class="resumen-acuerdo-item"><strong>Monto total</strong>${dineroCliente(acuerdo.monto_total)}</div>
                <div class="resumen-acuerdo-item"><strong>Plan</strong>${cantidad} cuotas de ${dineroCliente(acuerdo.monto_cuota)}</div>
                <div class="resumen-acuerdo-item"><strong>Período</strong>${escapeHtml(desde)} a ${escapeHtml(hasta)}</div>
                <div class="resumen-acuerdo-item"><strong>Cuotas previas pagadas</strong>${previas}</div>
                <div class="resumen-acuerdo-item"><strong>Cuotas pagadas en sistema</strong>${pagosSistema}</div>
                <div class="resumen-acuerdo-item"><strong>Cuotas pendientes</strong>${pendientes}</div>
            </div>
            <h3 class="mini-title">Períodos del acuerdo</h3>
            <table>
                <thead><tr><th>Período</th><th>Estado</th><th>Acción</th></tr></thead>
                <tbody>${filas}</tbody>
            </table>`;

        resumenAcuerdo.querySelectorAll(".seleccionar-periodo-acuerdo").forEach((boton) => {
            boton.addEventListener("click", () => {
                if (periodoInput) {
                    periodoInput.value = boton.dataset.periodo || "";
                    periodoInput.dispatchEvent(new Event("input", { bubbles: true }));
                }
                if (montoInput && Number(acuerdo.monto_cuota || 0) > 0) {
                    montoInput.value = acuerdo.monto_cuota;
                }
                validarDuplicadoCliente(false);
                periodoInput?.focus();
            });
        });
    };

    const validarDuplicadoCliente = (mostrarAlerta = false) => {
        const empresaId = empresaIdInput?.value || "";
        const tipo = tipoInput?.value || "";
        const tipoPago = tipoPagoInput?.value || "";
        const periodo = periodoInput?.value || "";
        const pagoId = pagoIdInput?.value || "";
        const duplicado = buscarPagoDuplicado(empresaId, tipo, periodo, tipoPago, pagoId);
        const clave = duplicado ? `${empresaId}|${tipo}|${periodoNormalizado(periodo)}|${tipoPago}` : "";

        if (avisoDuplicado) {
            avisoDuplicado.textContent = duplicado ? mensajePagoDuplicado : "";
            avisoDuplicado.style.display = duplicado ? "block" : "none";
        }

        if (duplicado && mostrarAlerta && claveDuplicadaAvisada !== clave) {
            alert(mensajePagoDuplicado);
            claveDuplicadaAvisada = clave;
        } else if (!duplicado) {
            claveDuplicadaAvisada = "";
        }

        return duplicado;
    };

    empresaIdInput?.addEventListener("change", () => {
        validarDuplicadoCliente(true);
        renderResumenAcuerdoPago();
    });
    tipoInput?.addEventListener("change", () => {
        validarDuplicadoCliente(true);
        renderResumenAcuerdoPago();
    });
    tipoPagoInput?.addEventListener("change", () => {
        validarDuplicadoCliente(true);
        renderResumenAcuerdoPago();
    });
    periodoInput?.addEventListener("change", () => validarDuplicadoCliente(true));
    periodoInput?.addEventListener("input", () => validarDuplicadoCliente(false));

    pagoForm.addEventListener("submit", (event) => {
        const empresaId = empresaIdInput?.value || "";
        const periodo = periodoInput;
        const tipoPago = tipoPagoInput?.value || "";
        if (!empresaId) {
            event.preventDefault();
            alert("Seleccioná una empresa desde el buscador.");
            pagoForm.querySelector(".empresa-picker-input")?.focus();
            return;
        }
        if (periodo && !periodoValidoCliente(periodo.value)) {
            event.preventDefault();
            alert("El periodo debe tener formato MM/AA.");
            periodo.focus();
            return;
        }
        if (formaPagoInput?.value === "Cheque") {
            const fechas = Array.from(chequesFechas?.querySelectorAll('input[name="cheque_fecha[]"]') || []);
            const ahora = new Date();
            const hoy = `${ahora.getFullYear()}-${String(ahora.getMonth() + 1).padStart(2, "0")}-${String(ahora.getDate()).padStart(2, "0")}`;
            if (!fechas.length || fechas.some((input) => !input.value)) {
                event.preventDefault();
                alert("Todas las fechas de cobro de los cheques deben estar completas.");
                fechas.find((input) => !input.value)?.focus();
                return;
            }
            const fechaNoFutura = fechas.find((input) => input.value <= hoy && input.value !== (input.dataset.original || ""));
            if (fechaNoFutura) {
                event.preventDefault();
                alert("Las fechas de cobro de los cheques deben ser futuras.");
                fechaNoFutura.focus();
                return;
            }
        }
        if (tipoPago === "Cuota de acuerdo") {
            const empresa = obtenerEmpresa(empresaId);
            const tipo = tipoInput?.value || "";
            if (!empresa || !tieneDatosAcuerdo(empresa, tipo)) {
                event.preventDefault();
                alert("Para cargar una cuota debe existir un acuerdo para esta empresa y este tipo.");
                return;
            }
            if (cuotaEsperadaEmpresaPeriodo(empresa, periodo?.value || "", tipo) <= 0) {
                event.preventDefault();
                alert("El período seleccionado no pertenece al acuerdo.");
                periodo?.focus();
                return;
            }
            if (cuotaPreviaPagadaEmpresaPeriodo(empresa, periodo?.value || "", tipo)) {
                event.preventDefault();
                alert("El período seleccionado ya está cubierto por una cuota previa pagada.");
                periodo?.focus();
                return;
            }
        }
        if (validarDuplicadoCliente(false)) {
            event.preventDefault();
            alert(mensajePagoDuplicado);
            periodo?.focus();
        }
    });

    validarDuplicadoCliente(false);
    renderResumenAcuerdoPago();
}

function normalizarTexto(valor) {
    return (valor || "")
        .toString()
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^a-z0-9]/g, " ")
        .replace(/\s+/g, " ")
        .trim();
}

function formaSocietariaRazon(valor) {
    const texto = (valor || "").toString().trim().toUpperCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
    if (/(?:^|[^A-Z0-9])S\s*\.?\s*A\s*\.?\s*S\s*\.?\s*$/.test(texto)) return "SAS";
    if (/(?:^|[^A-Z0-9])S\s*\.?\s*R\s*\.?\s*L\s*\.?\s*$/.test(texto)) return "SRL";
    if (/(?:^|[^A-Z0-9])S\s*\.?\s*A\s*\.?\s*$/.test(texto)) return "SA";
    return "";
}

function normalizarRazonEmpresa(valor) {
    return normalizarTexto(valor)
        .replace(/\b(?:s a s|s r l|s a|sas|srl|sa)\b/g, " ")
        .replace(/\s+/g, " ")
        .trim();
}

function claveExactaRazonEmpresa(valor) {
    return `${normalizarRazonEmpresa(valor)}|${formaSocietariaRazon(valor)}`;
}

function palabrasImportantesEmpresa(valor) {
    return [...new Set(normalizarRazonEmpresa(valor).split(" ").filter((palabra) => palabra.length >= 3))];
}

function razonesEmpresasParecidas(a, b) {
    const razonA = normalizarRazonEmpresa(a);
    const razonB = normalizarRazonEmpresa(b);
    if (!razonA || !razonB) return false;
    if (razonA === razonB || razonA.includes(razonB) || razonB.includes(razonA)) return true;
    const palabrasB = new Set(palabrasImportantesEmpresa(razonB));
    return palabrasImportantesEmpresa(razonA).filter((palabra) => palabrasB.has(palabra)).length >= 2;
}

function coincidenciasFormularioEmpresa(razon, cuit, empresaId = "") {
    const cuitNormalizado = (cuit || "").replace(/\D/g, "");
    const claveExacta = claveExactaRazonEmpresa(razon);
    let coincidenciaCuit = null;
    let coincidenciaExacta = null;
    const parecidas = [];

    empresasData.forEach((empresa) => {
        if ((empresa.id || "") === empresaId) return;
        const cuitExistente = (empresa.cuit || "").replace(/\D/g, "");
        if (!coincidenciaCuit && cuitNormalizado && cuitExistente === cuitNormalizado) coincidenciaCuit = empresa;

        if (
            !coincidenciaExacta &&
            normalizarRazonEmpresa(empresa.razon || "") &&
            claveExactaRazonEmpresa(empresa.razon || "") === claveExacta
        ) {
            coincidenciaExacta = empresa;
        } else if (razonesEmpresasParecidas(razon, empresa.razon || "")) {
            parecidas.push(empresa);
        }
    });

    return { cuit: coincidenciaCuit, exacta: coincidenciaExacta, parecidas };
}

function abrirFichaEmpresaCoincidente(empresaId) {
    activarTab("buscar-empresa");
    seleccionarEmpresaFicha(empresaId);
    document.getElementById("fichaEmpresa")?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function configurarControlDuplicadosEmpresa() {
    const form = document.getElementById("empresaForm");
    if (!form) return;

    const razon = document.getElementById("empresaRazon");
    const cuit = document.getElementById("empresaCuit");
    const empresaId = form.querySelector('input[name="empresa_id"]');
    const confirmar = document.getElementById("confirmarEmpresaParecida");
    const panel = document.getElementById("empresaDuplicadosCliente");
    const guardar = document.getElementById("guardarEmpresa");
    let ultimaFirma = `${razon?.value.trim() || ""}|${cuit?.value || ""}`;

    const filaEmpresa = (empresa, etiqueta) => `<div class="empresa-coincidencia">
        <span><strong>${escapeHtml(empresa.razon || "")}</strong>${empresa.cuit ? ` · CUIT ${escapeHtml(empresa.cuit)}` : ""}</span>
        <button type="button" class="ver-empresa-coincidente" data-empresa="${escapeHtml(empresa.id || "")}">${etiqueta}</button>
    </div>`;

    const conectarBotones = () => {
        panel?.querySelectorAll(".ver-empresa-coincidente").forEach((boton) => {
            boton.addEventListener("click", () => abrirFichaEmpresaCoincidente(boton.dataset.empresa || ""));
        });
    };

    const evaluar = () => {
        const nombre = razon?.value.trim() || "";
        const firma = `${nombre}|${cuit?.value || ""}`;
        if (firma !== ultimaFirma && confirmar) confirmar.value = "";
        ultimaFirma = firma;

        if (!nombre) {
            if (panel) panel.innerHTML = "";
            return { bloqueada: false, parecidas: [] };
        }

        const coincidencias = coincidenciasFormularioEmpresa(nombre, cuit?.value || "", empresaId?.value || "");
        if (coincidencias.cuit) {
            if (panel) panel.innerHTML = `<div class="empresa-duplicados bloqueo"><p class="error">Ya existe una empresa cargada con ese CUIT.</p>${filaEmpresa(coincidencias.cuit, "Ver empresa existente")}</div>`;
            conectarBotones();
            return { bloqueada: true, parecidas: [] };
        }
        if (coincidencias.exacta) {
            if (panel) panel.innerHTML = `<div class="empresa-duplicados bloqueo"><p class="error">Esta empresa ya parece estar cargada.</p>${filaEmpresa(coincidencias.exacta, "Ver empresa existente")}</div>`;
            conectarBotones();
            return { bloqueada: true, parecidas: [] };
        }
        if (coincidencias.parecidas.length) {
            if (panel) panel.innerHTML = `<div class="empresa-duplicados"><p><strong>Hay empresas parecidas ya cargadas. Revisá antes de guardar para evitar duplicados.</strong></p>
                ${coincidencias.parecidas.slice(0, 10).map((empresa) => filaEmpresa(empresa, "Ver ficha")).join("")}
                <button type="button" id="confirmarGuardarEmpresa">Guardar de todos modos</button>
            </div>`;
            conectarBotones();
            document.getElementById("confirmarGuardarEmpresa")?.addEventListener("click", () => {
                if (confirmar) confirmar.value = "1";
                form.requestSubmit(guardar);
            });
            return { bloqueada: false, parecidas: coincidencias.parecidas };
        }

        if (panel) panel.innerHTML = "";
        return { bloqueada: false, parecidas: [] };
    };

    razon?.addEventListener("input", evaluar);
    cuit?.addEventListener("input", evaluar);
    form.addEventListener("submit", (event) => {
        const resultado = evaluar();
        if (resultado.bloqueada) {
            event.preventDefault();
            panel?.scrollIntoView({ behavior: "smooth", block: "center" });
            return;
        }
        if (resultado.parecidas.length && confirmar?.value !== "1") {
            event.preventDefault();
            panel?.scrollIntoView({ behavior: "smooth", block: "center" });
        }
    });

    document.querySelectorAll(".ver-empresa-coincidente").forEach((boton) => {
        boton.addEventListener("click", () => abrirFichaEmpresaCoincidente(boton.dataset.empresa || ""));
    });
    evaluar();
}

function distanciaEdicion(a, b) {
    if (a === b) return 0;
    if (!a.length) return b.length;
    if (!b.length) return a.length;
    const fila = Array.from({ length: b.length + 1 }, (_, indice) => indice);
    for (let i = 1; i <= a.length; i++) {
        let diagonal = fila[0];
        fila[0] = i;
        for (let j = 1; j <= b.length; j++) {
            const anterior = fila[j];
            fila[j] = Math.min(
                fila[j] + 1,
                fila[j - 1] + 1,
                diagonal + (a[i - 1] === b[j - 1] ? 0 : 1)
            );
            diagonal = anterior;
        }
    }
    return fila[b.length];
}

function coincideBusqueda(valor, consulta) {
    const texto = normalizarRazonEmpresa(valor);
    const terminos = normalizarRazonEmpresa(consulta).split(" ").filter(Boolean);
    if (!terminos.length) return true;
    const palabras = texto.split(" ").filter(Boolean);
    const textoCompacto = texto.replace(/\s/g, "");

    return terminos.every((termino) =>
        texto.includes(termino) ||
        textoCompacto.includes(termino.replace(/\s/g, "")) ||
        palabras.some((palabra) =>
            palabra.startsWith(termino) ||
            termino.startsWith(palabra) ||
            (termino.length >= 5 && Math.abs(palabra.length - termino.length) <= 2 && distanciaEdicion(palabra, termino) <= 2)
        )
    );
}

function textoNormalizado(valor) {
    return normalizarTexto(valor);
}

function escapeHtml(valor) {
    return (valor ?? "").toString().replace(/[&<>"']/g, (char) => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
    }[char]));
}

function dineroCliente(valor) {
    const numero = Math.max(Number(valor) || 0, 0);
    return "$" + numero.toLocaleString("es-AR", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function periodoNormalizado(valor) {
    const periodo = (valor || "").toString().trim();
    if (/^\d{4}-\d{2}$/.test(periodo)) {
        return periodo.slice(5, 7) + "/" + periodo.slice(2, 4);
    }
    if (/^\d{2}\/\d{2}$/.test(periodo)) {
        return periodo;
    }
    if (/^\d{2}\/\d{2}\/\d{2}$/.test(periodo)) {
        return periodo.slice(3);
    }
    return periodo;
}

function periodoAIndice(periodo) {
    const normalizado = periodoNormalizado(periodo);
    if (!periodoValidoCliente(normalizado)) return null;
    const [mes, anio] = normalizado.split("/").map(Number);
    return (2000 + anio) * 12 + mes;
}

function periodoAMesIndice(periodo) {
    const normalizado = periodoNormalizado(periodo);
    if (!periodoValidoCliente(normalizado)) return null;
    const [mes, anio] = normalizado.split("/").map(Number);
    return (2000 + anio) * 12 + mes;
}

function periodoDesdeIndice(indice) {
    const anioCompleto = Math.floor((indice - 1) / 12);
    const mes = indice - anioCompleto * 12;
    return String(mes).padStart(2, "0") + "/" + String(anioCompleto % 100).padStart(2, "0");
}

function acuerdoEmpresaTipo(empresa, tipo) {
    const base = {
        monto_total: 0,
        cantidad_cuotas: 1,
        monto_cuota: 0,
        cuotas_pagadas_previas: 0,
        pagos_previos_ids: [],
        periodo_desde: "",
        periodo_hasta: "",
        observaciones: ""
    };
    if (empresa?.acuerdos && empresa.acuerdos[tipo]) {
        return { ...base, ...empresa.acuerdos[tipo] };
    }
    return {
        ...base,
        monto_total: empresa?.monto_total || 0,
        cantidad_cuotas: empresa?.cantidad_cuotas || 1,
        monto_cuota: empresa?.monto_cuota || 0,
        cuotas_pagadas_previas: empresa?.cuotas_pagadas_previas || 0,
        periodo_desde: empresa?.periodo_desde || "",
        periodo_hasta: empresa?.periodo_hasta || "",
        observaciones: empresa?.observaciones_acuerdo || ""
    };
}

function pagosPreviosIdsCliente(empresa, tipo) {
    const ids = acuerdoEmpresaTipo(empresa, tipo).pagos_previos_ids;
    return Array.isArray(ids) ? [...new Set(ids.map(String).filter(Boolean))] : [];
}

function pagoVinculadoComoPrevioCliente(pago, empresa = null) {
    const empresaPago = empresa || obtenerEmpresa(pago?.empresa_id || "");
    if (!empresaPago) return false;
    return pagosPreviosIdsCliente(empresaPago, pago?.tipo || "").includes(String(pago?.id || ""));
}

function pagosPreviosVinculadosCliente(empresa, tipo) {
    const ids = new Set(pagosPreviosIdsCliente(empresa, tipo));
    return pagosData.filter((pago) =>
        ids.has(String(pago.id || "")) &&
        (pago.empresa_id || "") === (empresa.id || "") &&
        (pago.tipo || "") === tipo
    );
}

function planEmpresa(empresa, tipo = "Obra Social") {
    return tieneDatosAcuerdo(empresa, tipo) ? "Acuerdo" : "Sin acuerdo";
}

function periodoAcuerdoEmpresa(empresa, tipo = "Obra Social") {
    const acuerdo = acuerdoEmpresaTipo(empresa, tipo);
    const desde = periodoNormalizado(acuerdo.periodo_desde || "");
    const hasta = periodoNormalizado(acuerdo.periodo_hasta || "");
    if (desde && hasta && desde !== hasta) return `${desde} a ${hasta}`;
    return desde || hasta || "";
}

function tieneDatosAcuerdo(empresa, tipo = "Obra Social") {
    const acuerdo = acuerdoEmpresaTipo(empresa, tipo);
    return Number(acuerdo.cantidad_cuotas || 0) >= 2 &&
        Number(acuerdo.monto_total || 0) > 0 &&
        Number(acuerdo.monto_cuota || 0) > 0 &&
        !!periodoNormalizado(acuerdo.periodo_desde || "") &&
        !!periodoNormalizado(acuerdo.periodo_hasta || "");
}

function cuotaEsperadaEmpresaPeriodo(empresa, periodo, tipo = "Obra Social") {
    const acuerdo = acuerdoEmpresaTipo(empresa, tipo);
    const cantidad = Number(acuerdo.cantidad_cuotas || 0);
    const consultado = periodoAMesIndice(periodo);
    const desde = periodoAMesIndice(acuerdo.periodo_desde || "");
    const hasta = periodoAMesIndice(acuerdo.periodo_hasta || "");
    if (cantidad < 2 || consultado === null) return 0;

    if (desde !== null && hasta !== null && consultado >= desde && consultado <= hasta) {
        return Math.max(Number(acuerdo.monto_cuota || 0), 0);
    }

    return 0;
}

function numeroCuotaEmpresaPeriodo(empresa, periodo, tipo = "Obra Social") {
    const acuerdo = acuerdoEmpresaTipo(empresa, tipo);
    const cantidad = Number(acuerdo.cantidad_cuotas || 0);
    const consultado = periodoAMesIndice(periodo);
    const desde = periodoAMesIndice(acuerdo.periodo_desde || "");
    if (cantidad < 2 || consultado === null || desde === null) return 0;
    const numero = consultado - desde + 1;
    return numero >= 1 && numero <= cantidad ? numero : 0;
}

function cuotaPreviaPagadaEmpresaPeriodo(empresa, periodo, tipo = "Obra Social") {
    const acuerdo = acuerdoEmpresaTipo(empresa, tipo);
    const previas = Math.max(Number(acuerdo.cuotas_pagadas_previas || 0), 0);
    const numero = numeroCuotaEmpresaPeriodo(empresa, periodo, tipo);
    return numero > 0 && numero <= previas;
}

function esCuotaAcuerdoPago(pago, empresa = null, tipo = "") {
    return tipoPagoCompatibleCliente(pago, empresa, tipo) === "Cuota de acuerdo";
}

function tipoPagoCompatibleCliente(pago, empresa = null, tipo = "") {
    if (["Pago al día", "Cuota de acuerdo", "Pago único / extraordinario"].includes(pago?.tipo_pago || "")) {
        return pago.tipo_pago;
    }
    if ((pago?.tipo_pago || "") === "Pago único") return "Pago único / extraordinario";
    if ((pago?.pago_tipo || "") === "Cuotas") return "Cuota de acuerdo";
    if ((pago?.pago_tipo || "") === "Pago total") return "Pago único / extraordinario";
    const empresaPago = empresa || obtenerEmpresa(pago?.empresa_id || "");
    const tipoPago = tipo || pago?.tipo || "";
    if (empresaPago && cuotaEsperadaEmpresaPeriodo(empresaPago, pago?.periodo || "", tipoPago) > 0) {
        return "Cuota de acuerdo";
    }
    return "Pago único / extraordinario";
}

function obtenerEmpresa(id) {
    return empresasData.find((empresa) => (empresa.id || "") === (id || "")) || null;
}

function empresaActivaCliente(empresa) {
    return !empresa || empresa.activa !== false;
}

function etiquetaEmpresa(empresa) {
    return empresa ? `${empresa.razon || ""} - ${empresa.cuit || ""}`.trim() : "";
}

function activarTab(tabId) {
    document.querySelectorAll(".tab-btn").forEach((btn) => {
        btn.classList.toggle("active", btn.dataset.tab === tabId);
    });
    document.querySelectorAll(".tab-panel").forEach((panel) => {
        panel.classList.toggle("active", panel.id === "tab-" + tabId);
    });
}

function actualizarBotonCard(card) {
    const boton = card.querySelector(".toggle-card");
    if (!boton) return;
    boton.textContent = card.classList.contains("is-collapsed") ? "Mostrar" : "Minimizar";
}

function abrirCard(card) {
    if (!card) return;
    card.classList.remove("is-collapsed");
    actualizarBotonCard(card);
}

function configurarCardsPlegables() {
    document.querySelectorAll(".collapsible-card").forEach((card) => {
        const boton = card.querySelector(".toggle-card");
        actualizarBotonCard(card);

        if (boton) {
            boton.addEventListener("click", () => {
                card.classList.toggle("is-collapsed");
                actualizarBotonCard(card);
            });
        }
    });

}

function seleccionarEmpresaPicker(hiddenName, empresaId) {
    const picker = document.querySelector(`.empresa-picker[data-hidden-name="${hiddenName}"]`);
    const empresa = obtenerEmpresa(empresaId);
    if (!picker || !empresa) return;

    const visible = picker.querySelector(".empresa-picker-input");
    const hidden = picker.querySelector(".empresa-picker-hidden");
    const resultados = picker.querySelector(".empresa-picker-results");
    if (visible) visible.value = etiquetaEmpresa(empresa);
    if (hidden) {
        hidden.value = empresa.id || "";
        hidden.dispatchEvent(new Event("change", { bubbles: true }));
    }
    if (resultados) {
        resultados.innerHTML = "";
        resultados.classList.remove("active");
    }
}

function configurarEmpresaPickers() {
    document.querySelectorAll(".empresa-picker").forEach((picker) => {
        const visible = picker.querySelector(".empresa-picker-input");
        const hidden = picker.querySelector(".empresa-picker-hidden");
        const resultados = picker.querySelector(".empresa-picker-results");
        if (!visible || !hidden || !resultados) return;

        visible.addEventListener("input", () => {
            hidden.value = "";
            hidden.dispatchEvent(new Event("change", { bubbles: true }));
            const texto = normalizarTexto(visible.value);
            if (texto.length < 2) {
                resultados.innerHTML = "";
                resultados.classList.remove("active");
                return;
            }

            const coincidencias = empresasData
                .filter((empresa) => empresaActivaCliente(empresa))
                .filter((empresa) => coincideBusqueda((empresa.razon || "") + " " + (empresa.cuit || ""), texto))
                .slice(0, 30);

            resultados.innerHTML = coincidencias.length
                ? coincidencias.map((empresa) => `<div class="empresa-picker-option" data-id="${escapeHtml(empresa.id || "")}">${escapeHtml(etiquetaEmpresa(empresa))}</div>`).join("")
                : '<div class="empresa-picker-option sin">Sin coincidencias</div>';
            resultados.classList.add("active");

            resultados.querySelectorAll(".empresa-picker-option[data-id]").forEach((opcion) => {
                opcion.addEventListener("click", () => {
                    seleccionarEmpresaPicker(hidden.name, opcion.dataset.id);
                    if (hidden.name === "acuerdo_empresa_id") cargarAcuerdoExistente();
                });
            });
        });

        visible.addEventListener("focus", () => {
            if (visible.value.length >= 2 && resultados.innerHTML) resultados.classList.add("active");
        });
    });

    document.addEventListener("click", (event) => {
        if (event.target.closest(".empresa-picker")) return;
        document.querySelectorAll(".empresa-picker-results").forEach((resultados) => resultados.classList.remove("active"));
    });
}

function configurarTabs() {
    document.querySelectorAll(".tab-btn, .tab-jump").forEach((boton) => {
        boton.addEventListener("click", () => activarTab(boton.dataset.tab));
    });
    const hashTab = window.location.hash ? window.location.hash.replace("#", "") : "";
    activarTab(hashTab && document.getElementById("tab-" + hashTab) ? hashTab : tabInicial);
}

function configurarFiltrosEmpresas() {
    const categoria = document.getElementById("filtroEmpresaCategoria");
    const plan = document.getElementById("filtroEmpresaPlan");
    const texto = document.getElementById("filtroEmpresaTexto");
    const estado = document.getElementById("filtroEmpresaEstado");
    const activa = document.getElementById("filtroEmpresaActiva");
    const limpiar = document.getElementById("limpiarFiltrosEmpresas");
    const filas = Array.from(document.querySelectorAll(".fila-empresa"));
    if (!categoria || !plan || !texto || !estado || !activa || !limpiar) return;

    const aplicar = () => {
        const categoriaValor = categoria.value;
        const planValor = plan.value;
        const busqueda = normalizarTexto(texto.value);
        const estadoValor = estado.value;
        const activaValor = activa.value;

        filas.forEach((fila) => {
            const coincideCategoria = !categoriaValor || fila.dataset[categoriaValor] === "1";
            const coincidePlan = !planValor || fila.dataset.plan === planValor;
            const coincideTexto = coincideBusqueda(fila.dataset.busqueda, busqueda);
            const coincideEstado = !estadoValor || fila.dataset.estado === estadoValor;
            const coincideActiva = activaValor === "todas" || (activaValor === "activas" && fila.dataset.activa === "1") || (activaValor === "inactivas" && fila.dataset.activa === "0");
            fila.classList.toggle("fila-oculta", !(coincideCategoria && coincidePlan && coincideTexto && coincideEstado && coincideActiva));
        });
    };

    categoria.addEventListener("change", aplicar);
    plan.addEventListener("change", aplicar);
    texto.addEventListener("input", aplicar);
    estado.addEventListener("change", aplicar);
    activa.addEventListener("change", aplicar);
    limpiar.addEventListener("click", () => {
        categoria.value = "";
        plan.value = "";
        texto.value = "";
        estado.value = "";
        activa.value = "activas";
        aplicar();
    });
    aplicar();
}

function configurarFiltrosPagos() {
    const texto = document.getElementById("filtroPagoTexto");
    const tipo = document.getElementById("filtroPagoTipo");
    const forma = document.getElementById("filtroPagoForma");
    const periodo = document.getElementById("filtroPagoPeriodo");
    const limpiar = document.getElementById("limpiarFiltrosPagos");
    const filas = Array.from(document.querySelectorAll(".fila-pago"));
    if (!texto || !tipo || !forma || !periodo || !limpiar) return;

    const aplicar = () => {
        const busqueda = normalizarTexto(texto.value);
        const periodoValor = periodo.value;

        filas.forEach((fila) => {
            const coincideTexto = coincideBusqueda(fila.dataset.busqueda, busqueda);
            const coincideTipo = !tipo.value || fila.dataset.tipo === tipo.value;
            const coincideForma = !forma.value || fila.dataset.forma === forma.value;
            const coincidePeriodo = !periodoValor || (fila.dataset.periodo || "").startsWith(periodoValor);
            fila.classList.toggle("fila-oculta", !(coincideTexto && coincideTipo && coincideForma && coincidePeriodo));
        });
    };

    texto.addEventListener("input", aplicar);
    tipo.addEventListener("change", aplicar);
    forma.addEventListener("change", aplicar);
    periodo.addEventListener("input", aplicar);
    limpiar.addEventListener("click", () => {
        texto.value = "";
        tipo.value = "";
        forma.value = "";
        periodo.value = "";
        aplicar();
    });
}

function configurarFiltrosAuditoria() {
    const usuario = document.getElementById("filtroAuditoriaUsuario");
    const accion = document.getElementById("filtroAuditoriaAccion");
    const texto = document.getElementById("filtroAuditoriaTexto");
    const desde = document.getElementById("filtroAuditoriaDesde");
    const hasta = document.getElementById("filtroAuditoriaHasta");
    const limpiar = document.getElementById("limpiarFiltrosAuditoria");
    const exportar = document.getElementById("exportarAuditoriaCsv");
    const sinResultados = document.getElementById("auditoriaSinResultados");
    const filas = Array.from(document.querySelectorAll(".fila-auditoria"));
    if (!usuario || !accion || !texto || !desde || !hasta || !limpiar || !exportar) return;

    const aplicar = () => {
        const usuarioValor = usuario.value;
        const accionValor = accion.value;
        const textoValor = texto.value;
        const desdeValor = desde.value;
        const hastaValor = hasta.value;
        let visibles = 0;

        filas.forEach((fila) => {
            const coincideUsuario = !usuarioValor || fila.dataset.usuario === usuarioValor;
            const coincideAccion = !accionValor || fila.dataset.accion === accionValor;
            const coincideTexto = coincideBusqueda(fila.dataset.detalle || "", textoValor);
            const fecha = fila.dataset.fecha || "";
            const coincideDesde = !desdeValor || (fecha && fecha >= desdeValor);
            const coincideHasta = !hastaValor || (fecha && fecha <= hastaValor);
            const visible = coincideUsuario && coincideAccion && coincideTexto && coincideDesde && coincideHasta;
            fila.classList.toggle("fila-oculta", !visible);
            if (visible) visibles++;
        });

        if (sinResultados) sinResultados.classList.toggle("fila-oculta", filas.length === 0 || visibles > 0);
    };

    const csvCampo = (valor) => `"${(valor ?? "").toString().replace(/"/g, '""')}"`;
    const exportarCsv = () => {
        aplicar();
        const filasVisibles = filas.filter((fila) => !fila.classList.contains("fila-oculta"));
        const lineas = [["Fecha", "Usuario", "Acción", "Detalle"].map(csvCampo).join(";")];
        filasVisibles.forEach((fila) => {
            const celdas = Array.from(fila.children).map((celda) => celda.textContent.trim());
            lineas.push(celdas.map(csvCampo).join(";"));
        });

        const blob = new Blob(["\uFEFF" + lineas.join("\n")], { type: "text/csv;charset=utf-8" });
        const url = URL.createObjectURL(blob);
        const enlace = document.createElement("a");
        enlace.href = url;
        enlace.download = "auditoria_" + new Date().toISOString().slice(0, 10) + ".csv";
        document.body.appendChild(enlace);
        enlace.click();
        enlace.remove();
        URL.revokeObjectURL(url);
    };

    [usuario, accion, texto, desde, hasta].forEach((control) => {
        control.addEventListener(control.tagName === "INPUT" && control.type === "text" ? "input" : "change", aplicar);
    });

    limpiar.addEventListener("click", () => {
        usuario.value = "";
        accion.value = "";
        texto.value = "";
        desde.value = "";
        hasta.value = "";
        aplicar();
    });
    exportar.addEventListener("click", (event) => {
        event.preventDefault();
        exportarCsv();
    });
    aplicar();
}

function totalPagadoCliente(empresaId, tipo) {
    return pagosData.reduce((total, pago) => {
        if ((pago.empresa_id || "") === empresaId && (pago.tipo || "") === tipo) {
            return total + (Number(pago.monto) || 0);
        }
        return total;
    }, 0);
}

function resumenFinancieroCliente(empresa, tipo) {
    const tieneAcuerdo = tieneDatosAcuerdo(empresa, tipo);
    const acuerdo = acuerdoEmpresaTipo(empresa, tipo);
    const pagosRegistrados = totalPagadoCliente(empresa.id || "", tipo);
    const montoCuota = tieneAcuerdo ? Math.max(Number(acuerdo.monto_cuota || 0), 0) : 0;
    const previas = tieneAcuerdo ? Math.max(Number(acuerdo.cuotas_pagadas_previas || 0), 0) : 0;
    const vinculadas = tieneAcuerdo ? pagosPreviosVinculadosCliente(empresa, tipo).length : 0;
    const previasSinRegistro = Math.max(previas - vinculadas, 0);
    const deuda = tieneAcuerdo ? Math.max(Number(acuerdo.monto_total || 0), 0) : 0;
    const cobrado = pagosRegistrados + previasSinRegistro * montoCuota;

    return {
        tieneAcuerdo,
        deuda,
        cobrado,
        saldo: Math.max(deuda - cobrado, 0),
        vinculadas,
        previasSinRegistro
    };
}

function renderAcuerdoResumen(empresa, tipo) {
    const acuerdo = acuerdoEmpresaTipo(empresa, tipo);
    if (!tieneDatosAcuerdo(empresa, tipo) || (Number(acuerdo.monto_total || 0) <= 0 && Number(acuerdo.monto_cuota || 0) <= 0)) {
        return `${tipo}: Sin acuerdo cargado`;
    }

    const cuotas = Math.max(Number(acuerdo.cantidad_cuotas || 2), 2);
    const previas = Math.min(Math.max(Number(acuerdo.cuotas_pagadas_previas || 0), 0), cuotas - 1);
    const detalle = `${cuotas} x ${dineroCliente(acuerdo.monto_cuota)}`;
    const periodo = periodoAcuerdoEmpresa(empresa, tipo);
    return `${tipo}: Acuerdo ${detalle}${previas ? " - previas pagadas: " + previas : ""}${periodo ? " (" + periodo + ")" : ""}`;
}

function resumenDetalleAcuerdo(empresa, tipo) {
    const acuerdo = acuerdoEmpresaTipo(empresa, tipo);
    if (!tieneDatosAcuerdo(empresa, tipo) || (Number(acuerdo.monto_total || 0) <= 0 && Number(acuerdo.monto_cuota || 0) <= 0)) {
        return `<div class="box"><div class="label">${escapeHtml(tipo)}</div><div class="sin">Sin acuerdo cargado</div></div>`;
    }

    const cantidad = Math.max(Number(acuerdo.cantidad_cuotas || 2), 2);
    const previas = Math.min(Math.max(Number(acuerdo.cuotas_pagadas_previas || 0), 0), cantidad - 1);
    const pagosPrevios = pagosPreviosVinculadosCliente(empresa, tipo);
    const idsPrevios = new Set(pagosPrevios.map((pago) => String(pago.id || "")));
    const montoCuota = Number(acuerdo.monto_cuota || 0);
    const pagosSistema = pagosData.filter((pago) =>
        (pago.empresa_id || "") === (empresa.id || "") &&
        (pago.tipo || "") === tipo &&
        !idsPrevios.has(String(pago.id || "")) &&
        esCuotaAcuerdoPago(pago, empresa, tipo) &&
        cuotaEsperadaEmpresaPeriodo(empresa, pago.periodo || "", tipo) > 0 &&
        !cuotaPreviaPagadaEmpresaPeriodo(empresa, pago.periodo || "", tipo)
    );
    const cuotasSistema = pagosSistema.length;
    const cuotasPendientes = Math.max(cantidad - previas - cuotasSistema, 0);
    const saldoEstimado = resumenFinancieroCliente(empresa, tipo).saldo;

    return `<div class="box">
<div class="label">${escapeHtml(tipo)}</div>
<div>Monto total acuerdo: ${dineroCliente(acuerdo.monto_total)}</div>
<div>Cantidad total de cuotas: ${cantidad}</div>
<div>Monto cuota: ${dineroCliente(montoCuota)}</div>
<div>Cuotas previas declaradas: ${previas}</div>
<div>Pagos previos vinculados: ${pagosPrevios.length}</div>
<div>Cuotas previas estimadas sin registro: ${Math.max(previas - pagosPrevios.length, 0)}</div>
<div>Cuotas registradas posteriores: ${cuotasSistema}</div>
<div>Cuotas pendientes: ${cuotasPendientes}</div>
<div>Saldo pendiente estimado: ${dineroCliente(saldoEstimado)}</div>
<div style="margin-top:10px"><a class="btn-danger" href="?eliminar_acuerdo=${encodeURIComponent(empresa.id || "")}&tipo_acuerdo=${encodeURIComponent(tipo)}&origen=ficha" onclick="return confirm('¿Eliminar este acuerdo? No se eliminarán los pagos ya cargados.')" title="Eliminar acuerdo" aria-label="Eliminar acuerdo">🗑️</a></div>
</div>`;
}

function fechaChequeMostrar(fecha) {
    const partes = (fecha || "").split("-");
    return partes.length === 3 ? `${partes[2]}/${partes[1]}/${partes[0]}` : (fecha || "");
}

function estadoChequeCliente(cheque) {
    if (cheque?.cobrado) return "Cobrado";
    const ahora = new Date();
    const hoy = `${ahora.getFullYear()}-${String(ahora.getMonth() + 1).padStart(2, "0")}-${String(ahora.getDate()).padStart(2, "0")}`;
    const fecha = cheque?.fecha_cobro || "";
    if (fecha === hoy) return "Vence hoy";
    if (fecha && fecha < hoy) return "Vencido";
    return "Pendiente";
}

function formaPagoConCheques(pago) {
    const cantidad = Array.isArray(pago?.cheques) ? pago.cheques.length : 0;
    const forma = pago?.forma_pago || (cantidad ? "Cheque" : "");
    return `${forma}${cantidad ? ` (${cantidad})` : ""}`;
}

function seleccionarEmpresaFicha(empresaId) {
    const empresa = obtenerEmpresa(empresaId);
    const ficha = document.getElementById("fichaEmpresa");
    if (!empresa || !ficha) return;

    const saldos = tiposInforme.map((tipo) => {
        return { tipo, ...resumenFinancieroCliente(empresa, tipo) };
    });

    const pagosEmpresa = pagosData.filter((pago) => (pago.empresa_id || "") === (empresa.id || ""));
    const chequesEmpresa = pagosEmpresa.flatMap((pago) =>
        (Array.isArray(pago.cheques) ? pago.cheques : []).map((cheque, indice) => ({
            pago,
            cheque,
            indice,
            estado: estadoChequeCliente(cheque)
        }))
    ).sort((a, b) => (a.cheque.fecha_cobro || "").localeCompare(b.cheque.fecha_cobro || ""));
    const totalGeneral = pagosEmpresa.reduce((total, pago) => total + (Number(pago.monto) || 0), 0);
    const totalPorTipo = (tipo) => pagosEmpresa
        .filter((pago) => (pago.tipo || "") === tipo)
        .reduce((total, pago) => total + (Number(pago.monto) || 0), 0);
    const ultimoPago = [...pagosEmpresa].sort((a, b) => ((b.fecha || b.fecha_carga || "").toString()).localeCompare((a.fecha || a.fecha_carga || "").toString()))[0] || null;
    const acuerdosActivos = tiposInforme.filter((tipo) => tieneDatosAcuerdo(empresa, tipo)).length;
    const cuotasPendientesEstimadas = tiposInforme.reduce((total, tipo) => {
        const acuerdo = acuerdoEmpresaTipo(empresa, tipo);
        if (!tieneDatosAcuerdo(empresa, tipo)) return total;
        const cantidad = Math.max(Number(acuerdo.cantidad_cuotas || 0), 0);
        const previas = Math.max(Number(acuerdo.cuotas_pagadas_previas || 0), 0);
        const cuotasSistema = pagosEmpresa.filter((pago) =>
            (pago.tipo || "") === tipo &&
            !pagoVinculadoComoPrevioCliente(pago, empresa) &&
            esCuotaAcuerdoPago(pago, empresa, tipo) &&
            cuotaEsperadaEmpresaPeriodo(empresa, pago.periodo || "", tipo) > 0 &&
            !cuotaPreviaPagadaEmpresaPeriodo(empresa, pago.periodo || "", tipo)
        ).length;
        return total + Math.max(cantidad - previas - cuotasSistema, 0);
    }, 0);
    ficha.innerHTML = `
<h3>${escapeHtml(empresa.razon || "")}</h3>
<p><strong>CUIT:</strong> ${escapeHtml(empresa.cuit || "")}</p>
<p><span class="estado ${empresaActivaCliente(empresa) ? "estado-ok" : "estado-deudor"}">${empresaActivaCliente(empresa) ? "Activa" : "Inactiva"}</span></p>
<div class="empresa-ficha-grid">
<div class="box"><div class="label">Total cobrado general</div><div class="num">${dineroCliente(totalGeneral)}</div></div>
<div class="box"><div class="label">Total cobrado Obra Social</div><div class="num">${dineroCliente(totalPorTipo("Obra Social"))}</div></div>
<div class="box"><div class="label">Total cobrado Sindicato</div><div class="num">${dineroCliente(totalPorTipo("Sindicato"))}</div></div>
<div class="box"><div class="label">Total cobrado Mutual</div><div class="num">${dineroCliente(totalPorTipo("Mutual"))}</div></div>
<div class="box"><div class="label">Último pago registrado</div><div>${ultimoPago ? `${escapeHtml(periodoNormalizado(ultimoPago.periodo || ""))} - ${escapeHtml(ultimoPago.fecha || "")} - ${dineroCliente(ultimoPago.monto)}` : '<span class="sin">Sin pagos</span>'}</div></div>
<div class="box"><div class="label">Acuerdos activos</div><div class="num">${acuerdosActivos}</div></div>
<div class="box"><div class="label">Cuotas pendientes estimadas</div><div class="num">${cuotasPendientesEstimadas}</div></div>
</div>
<div class="empresa-ficha-grid">
${saldos.map((s) => `<div class="box"><div class="label">${escapeHtml(s.tipo)}</div><div>Deuda: ${dineroCliente(s.deuda)}</div><div>Cobrado: ${dineroCliente(s.cobrado)}</div><div>Saldo: ${dineroCliente(s.saldo)}</div></div>`).join("")}
</div>
<h3 class="mini-title">Acuerdos vigentes</h3>
<p>${tiposInforme.map((tipo) => escapeHtml(renderAcuerdoResumen(empresa, tipo))).join("<br>")}</p>
<div class="empresa-ficha-grid">${tiposInforme.map((tipo) => resumenDetalleAcuerdo(empresa, tipo)).join("")}</div>
<h3 class="mini-title">Cheques pendientes</h3>
${chequesEmpresa.length ? `<table><thead><tr><th>Fecha de cobro</th><th>Estado</th><th>Acción</th></tr></thead><tbody>${chequesEmpresa.map((item) => {
    const clase = item.estado === "Cobrado" ? "estado-ok" : (item.estado === "Vencido" ? "estado-deudor" : (item.estado === "Vence hoy" ? "estado-parcial" : "estado-previa"));
    const accion = item.estado === "Cobrado"
        ? `<span class="sin">Cobrado por ${escapeHtml(item.cheque.usuario_cobrado || "")}</span>`
        : `<form method="post"><input type="hidden" name="pago_id" value="${escapeHtml(item.pago.id || "")}"><input type="hidden" name="cheque_indice" value="${item.indice}"><input type="hidden" name="empresa_id_destino" value="${escapeHtml(empresa.id || "")}"><button name="marcar_cheque_cobrado" value="1" class="btn-small">&#x2713; Marcar cobrado</button></form>`;
    return `<tr><td>${escapeHtml(fechaChequeMostrar(item.cheque.fecha_cobro || ""))}</td><td><span class="estado ${clase}">${escapeHtml(item.estado)}</span></td><td>${accion}</td></tr>`;
}).join("")}</tbody></table>` : '<p class="sin">Sin cheques registrados.</p>'}
<h3 class="mini-title">Pagos registrados</h3>
${pagosEmpresa.length ? `<table><thead><tr><th>Fecha</th><th>Tipo</th><th>Tipo de pago</th><th>Período</th><th>Monto</th><th>Forma</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>${pagosEmpresa.map((pago) => `<tr><td>${escapeHtml(pago.fecha || "")}</td><td>${escapeHtml(pago.tipo || "")}</td><td>${escapeHtml(tipoPagoCompatibleCliente(pago, empresa, pago.tipo || ""))}</td><td>${escapeHtml(periodoNormalizado(pago.periodo || ""))}</td><td>${dineroCliente(pago.monto)}</td><td>${escapeHtml(formaPagoConCheques(pago))}</td><td>${pagoVinculadoComoPrevioCliente(pago, empresa) ? '<span class="estado estado-previa">Cuota previa vinculada</span>' : '<span class="estado estado-ok">Pago registrado</span>'}</td><td><a class="btn-danger" href="?eliminar_pago=${encodeURIComponent(pago.id || "")}" onclick="return confirm('¿Eliminar este pago? Esta acción no elimina la empresa.')" title="Eliminar pago" aria-label="Eliminar pago">🗑️</a></td></tr>`).join("")}</tbody></table>` : '<p class="sin">Sin pagos registrados.</p>'}
<br>
<a class="btn-secundario" href="?editar_empresa=${encodeURIComponent(empresa.id || "")}">Editar empresa</a>
${empresaActivaCliente(empresa) ? `<a class="btn-danger" href="?eliminar_empresa=${encodeURIComponent(empresa.id || "")}" onclick="return confirm('La empresa quedará inactiva y sus pagos se conservarán. ¿Dar de baja empresa?')" title="Dar de baja empresa" aria-label="Dar de baja empresa">🗑️</a>` : ""}
<button type="button" class="btn-small ficha-cargar-pago" data-empresa="${escapeHtml(empresa.id || "")}">Cargar pago</button>
<button type="button" class="btn-small ficha-cargar-acuerdo" data-empresa="${escapeHtml(empresa.id || "")}">Cargar acuerdo</button>
`;

    ficha.querySelector(".ficha-cargar-pago")?.addEventListener("click", () => completarFormularioPago(empresa.id || "", "", ""));
    ficha.querySelector(".ficha-cargar-acuerdo")?.addEventListener("click", () => completarFormularioAcuerdo(empresa.id || "", ""));
}

function renderResultadosEmpresa(input, contenedor, limite = 12) {
    const texto = normalizarTexto(input.value);
    if (!texto) {
        contenedor.innerHTML = "";
        return;
    }

    const resultados = empresasData
        .filter((empresa) => empresaActivaCliente(empresa))
        .filter((empresa) => coincideBusqueda((empresa.razon || "") + " " + (empresa.cuit || ""), texto))
        .slice(0, limite);

    contenedor.innerHTML = resultados.length
        ? resultados.map((empresa) => `<div class="search-result"><span>${escapeHtml(empresa.razon || "")} - ${escapeHtml(empresa.cuit || "")}</span><button type="button" data-empresa="${escapeHtml(empresa.id || "")}">Ver ficha</button></div>`).join("")
        : '<p class="sin">Sin coincidencias.</p>';

    contenedor.querySelectorAll("button[data-empresa]").forEach((boton) => {
        boton.addEventListener("click", () => {
            activarTab("buscar-empresa");
            seleccionarEmpresaFicha(boton.dataset.empresa);
        });
    });
}

function ultimoPagoEmpresaTipo(empresaId, tipo) {
    const pagosTipo = pagosData
        .filter((pago) => (pago.empresa_id || "") === empresaId && (pago.tipo || "") === tipo)
        .sort((a, b) => {
            const fechaA = (a.fecha || a.fecha_carga || "").toString();
            const fechaB = (b.fecha || b.fecha_carga || "").toString();
            return fechaB.localeCompare(fechaA);
        });

    return pagosTipo[0] || null;
}

function completarFormularioPago(empresaId, tipo, periodo, tipoPagoInicial = "Pago al día") {
    if (buscarPagoDuplicado(empresaId, tipo, periodo, tipoPagoInicial)) {
        alert(mensajePagoDuplicado);
        return;
    }

    activarTab("cargar-pago");
    const card = document.getElementById("cargar-pago");
    abrirCard(card);

    const form = document.querySelector('form[enctype="multipart/form-data"]');
    if (form) {
        const pagoId = form.querySelector('input[name="pago_id"]');
        const comprobanteActual = form.querySelector('input[name="comprobante_actual"]');
        const tipoInput = form.querySelector('select[name="tipo"]');
        const tipoPagoInput = form.querySelector('select[name="tipo_pago"]');
        const periodoInput = form.querySelector('input[name="periodo"]');
        const monto = form.querySelector('input[name="monto"]');
        const titulo = card ? card.querySelector("h2") : null;
        const guardar = form.querySelector('button[name="guardar_pago"]');

        if (pagoId) pagoId.value = "";
        if (comprobanteActual) comprobanteActual.value = "";
        seleccionarEmpresaPicker("empresa_id", empresaId);
        if (tipoInput) {
            tipoInput.value = tipo;
            tipoInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
        if (tipoPagoInput) {
            tipoPagoInput.value = tipoPagoInicial;
            tipoPagoInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
        if (periodoInput) {
            periodoInput.value = periodo;
            periodoInput.dispatchEvent(new Event("input", { bubbles: true }));
        }
        if (titulo) titulo.textContent = "Cargar pago";
        if (guardar) guardar.textContent = "Guardar pago";
        if (monto) monto.focus();
    }

    window.scrollTo({ top: 0, behavior: "smooth" });
}

function completarFormularioAcuerdo(empresaId, tipo) {
    activarTab("cargar-acuerdo");
    const card = document.getElementById("cargar-acuerdo");
    abrirCard(card);

    const form = document.getElementById("acuerdoForm");
    if (form) {
        const tipoInput = form.querySelector('select[name="acuerdo_tipo"]');
        seleccionarEmpresaPicker("acuerdo_empresa_id", empresaId);
        if (tipoInput && tipo) tipoInput.value = tipo;
        cargarAcuerdoExistente();
    }

    window.scrollTo({ top: 0, behavior: "smooth" });
}

function cargarAcuerdoExistente() {
    const form = document.getElementById("acuerdoForm");
    if (!form) return;
    const acciones = document.getElementById("accionesAcuerdoExistente");
    const eliminar = document.getElementById("eliminarAcuerdoSeleccionado");
    const empresaId = form.querySelector('input[name="acuerdo_empresa_id"]')?.value || "";
    const tipo = form.querySelector('select[name="acuerdo_tipo"]')?.value || "";
    const empresa = obtenerEmpresa(empresaId);
    if (!empresa || !tipo) {
        if (acciones) acciones.style.display = "none";
        form.renderPagosPrevios?.([]);
        return;
    }

    const acuerdo = acuerdoEmpresaTipo(empresa, tipo);
    if (!tieneDatosAcuerdo(empresa, tipo)) {
        if (acciones) acciones.style.display = "none";
        form.querySelector('input[name="acuerdo_monto_total"]').value = "";
        form.querySelector('input[name="acuerdo_cantidad_cuotas"]').value = "";
        form.querySelector('input[name="acuerdo_monto_cuota"]').value = "";
        form.querySelector('input[name="acuerdo_cuotas_pagadas_previas"]').value = "0";
        form.querySelector('input[name="acuerdo_periodo_desde"]').value = "";
        form.querySelector('input[name="acuerdo_periodo_hasta"]').value = "";
        form.querySelector('textarea[name="acuerdo_observaciones"]').value = "";
        form.renderPagosPrevios?.([]);
        if (typeof form.actualizarResumen === "function") form.actualizarResumen();
        return;
    }

    if (acciones) acciones.style.display = "block";
    if (eliminar) {
        eliminar.href = `?eliminar_acuerdo=${encodeURIComponent(empresaId)}&tipo_acuerdo=${encodeURIComponent(tipo)}`;
    }
    form.querySelector('input[name="acuerdo_monto_total"]').value = acuerdo.monto_total || "";
    form.querySelector('input[name="acuerdo_cantidad_cuotas"]').value = acuerdo.cantidad_cuotas || "2";
    form.querySelector('input[name="acuerdo_monto_cuota"]').value = acuerdo.monto_cuota || "";
    form.querySelector('input[name="acuerdo_cuotas_pagadas_previas"]').value = acuerdo.cuotas_pagadas_previas || "0";
    form.querySelector('input[name="acuerdo_periodo_desde"]').value = periodoNormalizado(acuerdo.periodo_desde || "");
    form.querySelector('input[name="acuerdo_periodo_hasta"]').value = periodoNormalizado(acuerdo.periodo_hasta || "");
    form.querySelector('textarea[name="acuerdo_observaciones"]').value = acuerdo.observaciones || "";
    form.renderPagosPrevios?.(Array.isArray(acuerdo.pagos_previos_ids) ? acuerdo.pagos_previos_ids : []);
    if (typeof form.actualizarResumen === "function") form.actualizarResumen();
}

function configurarInformePeriodo() {
    const periodoInput = document.getElementById("informePeriodo");
    const tipoInput = document.getElementById("informeTipo");
    const consultar = document.getElementById("generarInformePeriodo");
    const esperadoEl = document.getElementById("informeEsperado");
    const totalEl = document.getElementById("informeTotal");
    const pendienteEl = document.getElementById("informePendiente");
    const pagaronEl = document.getElementById("informePagaron");
    const noPagaronEl = document.getElementById("informeNoPagaron");
    const periodoEl = document.getElementById("informePeriodoConsultado");
    const pagaronBody = document.getElementById("informePagaronBody");
    const noPagaronBody = document.getElementById("informeNoPagaronBody");
    const exportar = document.getElementById("exportarInformePeriodo");

    if (!periodoInput || !tipoInput || !consultar || !esperadoEl || !totalEl || !pendienteEl || !pagaronEl || !noPagaronEl || !periodoEl || !pagaronBody || !noPagaronBody) return;

    const render = () => {
        const periodo = periodoInput.value;
        const tiposSeleccionados = tipoInput.value ? [tipoInput.value] : tiposInforme;
        if (exportar) {
            exportar.href = `?exportar=informe&periodo=${encodeURIComponent(periodo)}&tipo=${encodeURIComponent(tipoInput.value || "")}`;
        }

        if (!periodoValidoCliente(periodo)) {
            esperadoEl.textContent = "$0,00";
            totalEl.textContent = "$0,00";
            pendienteEl.textContent = "$0,00";
            pagaronEl.textContent = "0";
            noPagaronEl.textContent = "0";
            periodoEl.textContent = "--";
            pagaronBody.innerHTML = '<tr><td colspan="10" class="sin">Ingresá un período MM/AA para consultar.</td></tr>';
            noPagaronBody.innerHTML = '<tr><td colspan="9" class="sin">Ingresá un período MM/AA para consultar.</td></tr>';
            return;
        }

        const pagosPeriodo = pagosData.filter((pago) =>
            periodoNormalizado(pago.periodo) === periodo && tiposSeleccionados.includes(pago.tipo || "")
        );

        const pagosAgrupados = new Map();
        pagosPeriodo.forEach((pago) => {
            const empresaPago = obtenerEmpresa(pago.empresa_id || "");
            const categoria = tipoPagoCompatibleCliente(pago, empresaPago, pago.tipo || "");
            const clave = (pago.empresa_id || "") + "|" + (pago.tipo || "") + "|" + categoria;
            const actual = pagosAgrupados.get(clave) || {
                empresaId: pago.empresa_id || "",
                tipo: pago.tipo || "",
                categoria,
                monto: 0,
                fechas: [],
                comprobantes: [],
                ids: [],
                vinculadoPrevio: false
            };
            actual.monto += Number(pago.monto) || 0;
            if (pago.fecha) actual.fechas.push(pago.fecha);
            if (pago.comprobante) actual.comprobantes.push(pago.comprobante);
            if (pago.id) actual.ids.push(pago.id);
            if (empresaPago && pagoVinculadoComoPrevioCliente(pago, empresaPago)) actual.vinculadoPrevio = true;
            pagosAgrupados.set(clave, actual);
        });

        const filasPagaron = [];
        const deudores = [];
        let totalEsperado = 0;
        let totalCubiertoPrevio = 0;

        empresasData.filter((empresa) => empresaActivaCliente(empresa)).forEach((empresa) => {
            tiposSeleccionados.forEach((tipo) => {
                const claveCuota = (empresa.id || "") + "|" + tipo + "|Cuota de acuerdo";
                const claveAlDia = (empresa.id || "") + "|" + tipo + "|Pago al día";
                const esperadoPorAcuerdo = cuotaEsperadaEmpresaPeriodo(empresa, periodo, tipo);
                const pagadaPrevia = cuotaPreviaPagadaEmpresaPeriodo(empresa, periodo, tipo);
                const pagoCuota = pagosAgrupados.get(claveCuota);
                const pagoAlDia = pagosAgrupados.get(claveAlDia);

                if (esperadoPorAcuerdo > 0 || pagoCuota) {
                    totalEsperado += esperadoPorAcuerdo;
                    if (pagadaPrevia && !pagoCuota) {
                        totalCubiertoPrevio += esperadoPorAcuerdo;
                        filasPagaron.push({
                            empresa, tipo, plan: "Cuota de acuerdo", esperado: esperadoPorAcuerdo,
                            pagado: esperadoPorAcuerdo, fechas: ["Cuota previa"], comprobantes: [], ids: [],
                            categoria: "Cuota de acuerdo", estado: "PAGADA PREVIA"
                        });
                    } else if (pagoCuota) {
                        if (pagoCuota.vinculadoPrevio) totalCubiertoPrevio += esperadoPorAcuerdo;
                        filasPagaron.push({
                            empresa, tipo, plan: "Cuota de acuerdo", esperado: esperadoPorAcuerdo,
                            pagado: pagoCuota.monto, fechas: pagoCuota.fechas, comprobantes: pagoCuota.comprobantes,
                            ids: pagoCuota.ids, categoria: "Cuota de acuerdo",
                            vinculadoPrevio: pagoCuota.vinculadoPrevio,
                            estado: pagoCuota.vinculadoPrevio ? "PAGADA PREVIA REGISTRADA" : "CUOTA DE ACUERDO PAGADA"
                        });
                    } else {
                        deudores.push({
                            empresa, tipo, plan: "Cuota de acuerdo",
                            categoria: "Cuota de acuerdo", esperado: esperadoPorAcuerdo,
                            estado: "CUOTA DE ACUERDO PENDIENTE"
                        });
                    }
                }

                if (pagoAlDia) {
                    filasPagaron.push({
                        empresa, tipo, plan: "Pago al día", esperado: 0, pagado: pagoAlDia.monto,
                        fechas: pagoAlDia.fechas, comprobantes: pagoAlDia.comprobantes, ids: pagoAlDia.ids,
                        categoria: "Pago al día", estado: "PAGADO AL DÍA"
                    });
                } else {
                    deudores.push({
                        empresa, tipo, plan: "Pago al día", categoria: "Pago al día",
                        esperado: 0, estado: "PENDIENTE PAGO AL DÍA"
                    });
                }
            });
        });

        pagosAgrupados.forEach((pago, clave) => {
            if (filasPagaron.some((fila) => (fila.empresa?.id || "") + "|" + fila.tipo + "|" + fila.categoria === clave)) return;
            const empresa = obtenerEmpresa(pago.empresaId);
            filasPagaron.push({
                empresa,
                tipo: pago.tipo,
                plan: pago.categoria,
                esperado: pago.categoria === "Cuota de acuerdo" && empresa ? cuotaEsperadaEmpresaPeriodo(empresa, periodo, pago.tipo) : 0,
                pagado: pago.monto,
                fechas: pago.fechas,
                comprobantes: pago.comprobantes,
                ids: pago.ids,
                categoria: pago.categoria,
                vinculadoPrevio: pago.vinculadoPrevio,
                estado: pago.vinculadoPrevio
                    ? "PAGADA PREVIA REGISTRADA"
                    : (pago.categoria === "Cuota de acuerdo"
                        ? "CUOTA DE ACUERDO PAGADA"
                        : (pago.categoria === "Pago al día" ? "PAGADO AL DÍA" : "PAGO EXTRAORDINARIO"))
            });
        });

        const totalPagado = filasPagaron
            .filter((fila) => fila.estado !== "PAGADA PREVIA")
            .reduce((total, fila) => total + fila.pagado, 0);
        const totalPagadoCuotas = filasPagaron
            .filter((fila) => fila.estado !== "PAGADA PREVIA" && !fila.vinculadoPrevio && fila.categoria === "Cuota de acuerdo")
            .reduce((total, fila) => total + fila.pagado, 0);
        const pendiente = Math.max(totalEsperado - totalPagadoCuotas - totalCubiertoPrevio, 0);
        const empresasQuePagaron = new Set(filasPagaron.map((fila) => fila.empresa?.id || "").filter(Boolean));
        const empresasQueNoPagaron = new Set(deudores.map((fila) => fila.empresa.id || "").filter(Boolean));

        esperadoEl.textContent = dineroCliente(totalEsperado);
        totalEl.textContent = dineroCliente(totalPagado);
        pendienteEl.textContent = dineroCliente(pendiente);
        pagaronEl.textContent = empresasQuePagaron.size.toString();
        noPagaronEl.textContent = empresasQueNoPagaron.size.toString();
        periodoEl.textContent = periodo;

        if (filasPagaron.length === 0) {
            pagaronBody.innerHTML = '<tr><td colspan="10" class="sin">No hay pagos registrados para este período y tipo.</td></tr>';
        } else {
            pagaronBody.innerHTML = filasPagaron.map((fila) => {
                const estadoClase = ["PAGADA PREVIA", "PAGADA PREVIA REGISTRADA"].includes(fila.estado)
                    ? "estado-previa"
                    : (["PAGADO AL DÍA", "CUOTA DE ACUERDO PAGADA", "PAGO EXTRAORDINARIO"].includes(fila.estado) ? "estado-ok" : "estado-parcial");
                const comprobantes = (fila.comprobantes || []).length
                    ? fila.comprobantes.map((comp, index) => `<a href="${escapeHtml(comp)}" target="_blank" title="Ver">👁️</a> <a href="${escapeHtml(comp)}" download title="Descargar">⬇️</a>${index < fila.comprobantes.length - 1 ? " " : ""}`).join("")
                    : '<span class="sin">Sin comprobante</span>';
                const acciones = (fila.ids || []).length
                    ? fila.ids.map((id) => `<a class="btn-danger" href="?eliminar_pago=${encodeURIComponent(id)}" onclick="return confirm('¿Eliminar este pago? Esta acción no elimina la empresa.')" title="Eliminar pago" aria-label="Eliminar pago">🗑️</a>`).join(" ")
                    : '<span class="sin">Sin acciones</span>';

                return `<tr>
<td>${escapeHtml(fila.empresa ? fila.empresa.razon : "Empresa eliminada")}</td>
<td>${escapeHtml(fila.empresa ? fila.empresa.cuit : "")}</td>
<td><span class="badge">${escapeHtml(fila.tipo || "")}</span></td>
<td>${escapeHtml(fila.plan)}</td>
<td>${dineroCliente(fila.esperado)}</td>
<td>${dineroCliente(fila.pagado)}</td>
<td><span class="estado ${estadoClase}">${escapeHtml(fila.estado)}</span></td>
<td>${escapeHtml((fila.fechas || []).join(", "))}</td>
<td>${comprobantes}</td>
<td>${acciones}</td>
</tr>`;
            }).join("");
        }

        if (deudores.length === 0) {
            noPagaronBody.innerHTML = '<tr><td colspan="9" class="sin">No hay empresas pendientes para este período y tipo.</td></tr>';
        } else {
            noPagaronBody.innerHTML = deudores.map(({ empresa, tipo, plan, categoria, esperado, estado }) => {
                const ultimoPago = ultimoPagoEmpresaTipo(empresa.id || "", tipo);
                const pagoYaCargado = buscarPagoDuplicado(empresa.id || "", tipo, periodo, categoria);
                const ultimo = ultimoPago
                    ? `${escapeHtml(periodoNormalizado(ultimoPago.periodo))} - ${escapeHtml(ultimoPago.fecha || "")} - ${dineroCliente(ultimoPago.monto)}`
                    : '<span class="sin">Sin pagos previos</span>';
                return `<tr>
<td>${escapeHtml(empresa.razon || "")}</td>
<td>${escapeHtml(empresa.cuit || "")}</td>
<td><span class="badge">${escapeHtml(tipo)}</span></td>
<td>${escapeHtml(plan)}</td>
<td>${dineroCliente(esperado)}</td>
<td>${escapeHtml(periodoAcuerdoEmpresa(empresa, tipo) || periodo)}</td>
<td>${ultimo}</td>
<td><span class="estado estado-deudor">${escapeHtml(estado || "DEUDOR")}</span></td>
<td>${pagoYaCargado
    ? '<button type="button" class="btn-small" disabled title="Ya existe un pago exacto para esta empresa, tipo, período y tipo de pago">Pago ya cargado</button>'
    : `<button type="button" class="btn-small cargar-pago-informe" data-empresa="${escapeHtml(empresa.id || "")}" data-tipo="${escapeHtml(tipo)}" data-periodo="${escapeHtml(periodo)}" data-tipo-pago="${escapeHtml(categoria)}">Cargar pago</button>`}</td>
</tr>`;
            }).join("");

            noPagaronBody.querySelectorAll(".cargar-pago-informe").forEach((boton) => {
                boton.addEventListener("click", () => {
                    completarFormularioPago(boton.dataset.empresa, boton.dataset.tipo, boton.dataset.periodo, boton.dataset.tipoPago);
                });
            });
        }
    };

    consultar.addEventListener("click", render);
    periodoInput.addEventListener("input", () => {
        if (periodoValidoCliente(periodoInput.value)) render();
    });
    tipoInput.addEventListener("change", render);
}

function configurarBuscadoresEmpresa() {
    const homeInput = document.getElementById("homeEmpresaSearch");
    const homeResultados = document.getElementById("homeEmpresaResultados");
    const fichaInput = document.getElementById("buscadorFichaEmpresa");
    const fichaResultados = document.getElementById("resultadosFichaEmpresa");

    if (homeInput && homeResultados) {
        homeInput.addEventListener("input", () => renderResultadosEmpresa(homeInput, homeResultados, 6));
    }
    if (fichaInput && fichaResultados) {
        fichaInput.addEventListener("input", () => renderResultadosEmpresa(fichaInput, fichaResultados, 20));
    }

    const acuerdoEmpresa = document.querySelector('input[name="acuerdo_empresa_id"]');
    const acuerdoTipo = document.getElementById("acuerdoTipo");
    if (acuerdoEmpresa) acuerdoEmpresa.addEventListener("change", cargarAcuerdoExistente);
    if (acuerdoTipo) acuerdoTipo.addEventListener("change", cargarAcuerdoExistente);
}

configurarTabs();
configurarCardsPlegables();
configurarControlDuplicadosEmpresa();
configurarEmpresaPickers();
configurarBuscadoresEmpresa();
configurarInformePeriodo();
configurarFiltrosEmpresas();
configurarFiltrosPagos();
configurarFiltrosAuditoria();
const empresaFichaInicial = <?= json_encode($_GET["ver_empresa"] ?? "", JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
if (empresaFichaInicial) seleccionarEmpresaFicha(empresaFichaInicial);
</script>
</body>
</html>
