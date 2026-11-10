<?php
/*
 * Formulario de contacto — proteccionconandrearosso.com
 *
 * Qué hace con cada contacto:
 *   1. Lo envía por correo a Andrea.
 *   2. Guarda una copia en /home/<usuario>/contactos-web/contactos.csv
 *      (fuera de public_html, así nadie puede verla desde internet).
 *   3. Si se configura $WEBHOOK_CRM, lo envía también al CRM.
 */

// ===================== CONFIGURACIÓN =====================
$DESTINO     = 'R3175@aol.com';                           // Correo que recibe los contactos
$REMITENTE   = 'no-reply@proteccionconandrearosso.com';   // Debe ser del mismo dominio
$ASUNTO      = 'Nuevo contacto desde proteccionconandrearosso.com';
$WEBHOOK_CRM = '';                                        // URL del webhook del CRM (se completa más adelante)
date_default_timezone_set('America/New_York');
// ==========================================================

$esAjax = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function responder($ok, $mensaje, $esAjax, $codigo = 200) {
    if ($esAjax) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: /?enviado=' . ($ok ? '1' : '0') . '#contacto', true, 303);
    }
    exit;
}

function campo($nombre, $max = 200) {
    $v = trim((string)($_POST[$nombre] ?? ''));
    $v = strip_tags($v);
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    return mb_substr($v, 0, $max, 'UTF-8');
}

function una_linea($v) {
    return trim(preg_replace('/[\r\n\t]+/', ' ', $v));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(false, 'Método no permitido.', $esAjax, 405);
}

// --- Protección contra spam ---
// Campo trampa invisible: las personas no lo ven, los robots sí lo llenan.
if (!empty($_POST['sitio_web'])) {
    responder(true, 'ok', $esAjax);
}
// Envíos hechos en menos de 3 segundos después de cargar la página.
$cargado = (int)($_POST['t'] ?? 0);
if ($cargado > 0 && (microtime(true) * 1000 - $cargado) < 3000) {
    responder(true, 'ok', $esAjax);
}

// --- Datos ---
$datos = [
    'nombre'           => una_linea(campo('nombre', 120)),
    'telefono'         => una_linea(campo('telefono', 40)),
    'email'            => una_linea(campo('email', 160)),
    'codigo_postal'    => una_linea(campo('codigo_postal', 10)),
    'fecha_nacimiento' => una_linea(campo('fecha_nacimiento', 10)),
    'interes'          => una_linea(campo('interes', 80)),
    'idioma'           => una_linea(campo('idioma', 20)),
    'mensaje'          => campo('mensaje', 2000),
];

// --- Validación ---
$errores = [];
if ($datos['nombre'] === '')   $errores[] = 'nombre';
if ($datos['telefono'] === '' || !preg_match('/^[0-9 +().\-]{7,25}$/', $datos['telefono'])) $errores[] = 'teléfono';
if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) $errores[] = 'correo';
if (!preg_match('/^\d{5}$/', $datos['codigo_postal'])) $errores[] = 'código postal';
$fn = DateTime::createFromFormat('Y-m-d', $datos['fecha_nacimiento']);
if (!$fn || $fn->format('Y-m-d') !== $datos['fecha_nacimiento'] || $fn > new DateTime()) $errores[] = 'fecha de nacimiento';

if ($errores) {
    responder(false, 'Revisa estos datos: ' . implode(', ', $errores) . '.', $esAjax, 422);
}

$fecha = date('Y-m-d H:i:s');
$fechaNacimientoTexto = $fn->format('m/d/Y');

// --- 1. Correo a Andrea ---
$cuerpo  = "Nuevo contacto desde la página web\n";
$cuerpo .= "Recibido: {$fecha} (hora del Este)\n\n";
$cuerpo .= "Nombre:              {$datos['nombre']}\n";
$cuerpo .= "Teléfono:            {$datos['telefono']}\n";
$cuerpo .= "Correo:              {$datos['email']}\n";
$cuerpo .= "Código postal:       {$datos['codigo_postal']}\n";
$cuerpo .= "Fecha de nacimiento: {$fechaNacimientoTexto}\n";
$cuerpo .= "Le interesa:         {$datos['interes']}\n";
$cuerpo .= "Idioma preferido:    {$datos['idioma']}\n\n";
$cuerpo .= "Mensaje:\n" . ($datos['mensaje'] !== '' ? $datos['mensaje'] : '(sin mensaje)') . "\n";

$asuntoCodificado = '=?UTF-8?B?' . base64_encode($ASUNTO . ' — ' . $datos['nombre']) . '?=';
$nombreRemitente  = '=?UTF-8?B?' . base64_encode('Página web Andrea Rosso') . '?=';
$nombreRespuesta  = '=?UTF-8?B?' . base64_encode($datos['nombre']) . '?=';

$cabeceras  = "From: {$nombreRemitente} <{$REMITENTE}>\r\n";
$cabeceras .= "Reply-To: {$nombreRespuesta} <{$datos['email']}>\r\n";
$cabeceras .= "MIME-Version: 1.0\r\n";
$cabeceras .= "Content-Type: text/plain; charset=UTF-8\r\n";
$cabeceras .= "Content-Transfer-Encoding: 8bit\r\n";

$correoEnviado = @mail($DESTINO, $asuntoCodificado, $cuerpo, $cabeceras, '-f' . $REMITENTE);

// --- 2. Copia de seguridad (CSV fuera de public_html) ---
$guardado = false;
$carpeta = dirname(rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/')) . '/contactos-web';
if (!is_dir($carpeta)) @mkdir($carpeta, 0700, true);
$archivo = $carpeta . '/contactos.csv';
$nuevo = !file_exists($archivo);
$fh = @fopen($archivo, 'a');
if ($fh) {
    if (flock($fh, LOCK_EX)) {
        if ($nuevo) {
            fputcsv($fh, ['Fecha', 'Nombre', 'Teléfono', 'Correo', 'Código postal', 'Fecha de nacimiento', 'Interés', 'Idioma', 'Mensaje']);
        }
        fputcsv($fh, [$fecha, $datos['nombre'], $datos['telefono'], $datos['email'], $datos['codigo_postal'],
                      $fechaNacimientoTexto, $datos['interes'], $datos['idioma'], una_linea($datos['mensaje'])]);
        flock($fh, LOCK_UN);
        $guardado = true;
    }
    fclose($fh);
}

// --- 3. CRM (cuando se configure) ---
if ($WEBHOOK_CRM !== '' && function_exists('curl_init')) {
    $ch = curl_init($WEBHOOK_CRM);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($datos + ['fecha' => $fecha, 'origen' => 'proteccionconandrearosso.com'], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

if ($correoEnviado || $guardado) {
    responder(true, 'Gracias, te contactaremos pronto.', $esAjax);
}
responder(false, 'No pudimos enviar tu solicitud. Escríbenos por WhatsApp al +1 (786) 371-4432.', $esAjax, 500);
