<?php
require 'vendor/autoload.php';

use Laminas\Db\Adapter\Adapter;
use Laminas\Mvc\Application;
use Ramsey\Uuid\Uuid;

// Configuración de la DB (se usa la misma de la app si está disponible)
$config = require 'config/autoload/global.php';
$configLocal = include 'config/autoload/local.php';

$dbConfig = array_merge($config['db'] ?? [], $configLocal['db'] ?? []);
if (empty($dbConfig)) {
    // Fallback
    $dbConfig = [
        'driver'   => 'Pdo_Mysql',
        'database' => 'mavoo_gestion',
        'username' => 'root',
        'password' => '',
        'hostname' => '127.0.0.1',
        'charset'  => 'utf8'
    ];
}

$db = new Adapter($dbConfig);

echo "Iniciando proceso de poblado de la Base de Datos...\n";

// 1. Crear usuario Admin de prueba si no existe (el ID 1 suele ser el owner)
$adminEmail = 'admin@admin.cl';
$adminResult = $db->query("SELECT id FROM users WHERE email = ? LIMIT 1", [$adminEmail]);
$adminId = null;

if ($row = $adminResult->current()) {
    $adminId = $row['id'];
    echo "Admin existente encontrado (ID: $adminId).\n";
} else {
    $uuid = Uuid::uuid4()->toString();
    $db->query("INSERT INTO users (uuid, name, email, password, role) VALUES (?, 'Admin Mavoo', ?, ?, 'admin')", [
        $uuid, 
        $adminEmail, 
        password_hash('admin123', PASSWORD_BCRYPT)
    ]);
    $adminId = $db->getDriver()->getLastGeneratedValue();
    echo "Generado usuario Admin (ID: $adminId).\n";
}

// 2. Poblar Deportes (Sistema de Reservas)
$deportes = ['padel', 'tenis', 'futbol', 'básquetbol'];

// Crear Sistemas / Recintos si no hay
$sistemaIds = [];
$canchaIds = [];

foreach ($deportes as $deporte) {
    $nombreSistema = "Complejo $deporte Mavoo";
    $slug = "complejo-$deporte-mavoo";
    $result = $db->query("SELECT id FROM reserva_sistemas WHERE nombre_negocio = ? LIMIT 1", [$nombreSistema]);
    
    if ($row = $result->current()) {
        $sistemaId = $row['id'];
    } else {
        $db->query("INSERT INTO reserva_sistemas (user_id, uuid, nombre_negocio, slug, created_at, updated_at) VALUES (?, UUID(), ?, ?, NOW(), NOW())", [
            $adminId, $nombreSistema, $slug
        ]);
        $sistemaId = $db->getDriver()->getLastGeneratedValue();
    }
    $sistemaIds[$deporte] = $sistemaId;
    
    // Crear Canchas
    for ($i = 1; $i <= 3; $i++) {
        $nombreCancha = "Cancha $i ($deporte)";
        $resultCancha = $db->query("SELECT id FROM reserva_canchas WHERE reserva_sistema_id = ? AND nombre = ? LIMIT 1", [$sistemaId, $nombreCancha]);
        if ($rowC = $resultCancha->current()) {
            $canchaIds[] = $rowC['id'];
        } else {
            $db->query("INSERT INTO reserva_canchas (reserva_sistema_id, nombre, precio_especifico, created_at, updated_at) VALUES (?, ?, 10000, NOW(), NOW())", [
                $sistemaId, $nombreCancha
            ]);
            $canchaIds[] = $db->getDriver()->getLastGeneratedValue();
        }
    }
}

// 3. Poblar Reservas (Arriendos) - Fechas Pasadas, Presentes y Futuras
$fechasReserva = [
    date('Y-m-d', strtotime('-10 days')),
    date('Y-m-d', strtotime('-5 days')),
    date('Y-m-d'), // Hoy
    date('Y-m-d', strtotime('+3 days')),
    date('Y-m-d', strtotime('+7 days'))
];

foreach ($canchaIds as $cId) {
    foreach ($fechasReserva as $fecha) {
        $db->query("INSERT INTO reservas (reserva_cancha_id, fecha, hora_inicio, hora_fin, estado, total_pago, es_pagado, cliente_nombre) VALUES (?, ?, '10:00', '11:30', 'booked', 10000, 1, 'Jugador Prueba')", [
            $cId, $fecha
        ]);
    }
}
echo "Generadas reservas (arriendos) de prueba.\n";

// 4. Poblar Eventos (Ligas/Torneos)
$estadosEventos = [
    ['titulo' => 'Liga de Verano Pasada', 'inicio' => '-30 days', 'cierre' => '-15 days', 'deporte' => 'padel'],
    ['titulo' => 'Torneo En Curso', 'inicio' => '-5 days', 'cierre' => '+10 days', 'deporte' => 'padel'],
    ['titulo' => 'Futuro Slam', 'inicio' => '+20 days', 'cierre' => '+40 days', 'deporte' => 'tenis'],
    ['titulo' => 'Copa de Invierno', 'inicio' => '-10 days', 'cierre' => '+5 days', 'deporte' => 'futbol']
];

foreach ($estadosEventos as $eventoData) {
    // Comprobar si ya existe
    $res = $db->query("SELECT id FROM mod_eventos WHERE titulo = ? LIMIT 1", [$eventoData['titulo']]);
    if ($row = $res->current()) {
        continue;
    }

    $eventoUuid = Uuid::uuid4()->toString();
    $db->query("INSERT INTO mod_eventos (uuid, titulo, user_id, deporte, created_at, updated_at, referencia_utc) VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())", [
        $eventoUuid,
        $eventoData['titulo'],
        $adminId,
        $eventoData['deporte']
    ]);
    $eventoId = $db->getDriver()->getLastGeneratedValue();
    
    // Asignar Evento-Usuario
    $db->query("INSERT IGNORE INTO evento_user (user_id, mod_evento_id) VALUES (?, ?)", [$adminId, $eventoId]);
    
    // Configurar Ajustes del Evento
    $inicio = date('Y-m-d H:i:s', strtotime($eventoData['inicio']));
    $cierre = date('Y-m-d H:i:s', strtotime($eventoData['cierre']));
    
    $db->query("INSERT INTO mod_gestion_ajustes (evento_id, deporte, inscripciones_inicio, inscripciones_cierre, descripcion_evento, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())", [
        $eventoId,
        $eventoData['deporte'],
        $inicio,
        $cierre,
        "<p>Este es un evento de prueba autogenerado para el deporte {$eventoData['deporte']}.</p>"
    ]);
}

echo "Generados eventos estables (pasados, en curso y futuros).\n";
echo "¡Datos masivos inyectados con éxito!\n";

