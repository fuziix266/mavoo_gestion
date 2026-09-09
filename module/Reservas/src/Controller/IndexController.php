<?php

declare(strict_types=1);

namespace Reservas\Controller;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Ramsey\Uuid\Uuid;
use Reservas\Service\AuthService;

class IndexController extends AbstractActionController
{
    private AdapterInterface $db;

    private AuthService $auth;

    public function __construct(AdapterInterface $db, AuthService $auth)
    {
        $this->db = $db;
        $this->auth = $auth;
    }

    public function indexAction()
    {
        return new ViewModel;
    }

    public function principalAction()
    {
        $sistema = $this->loadSistemaForCurrentUser();
        $request = $this->getRequest();
        $basePath = $this->getRequest()->getBasePath();

        if ($request->isPost()) {
            return $this->saveSistema($sistema, $request->getPost()->toArray());
        }

        $viewModel = new ViewModel([
            'sistema' => $sistema,
            'deporte' => null,
            'evento' => null,
            'eventos' => [],
            'ultimoAjuste' => null,
        ]);

        return $viewModel;
    }

    public function dashboardAction()
    {
        $sistema = $this->loadSistemaForCurrentUser();
        $request = $this->getRequest();
        $deporte = $request->getQuery('deporte', 'padel');
        $startDate = $request->getQuery('start_date');
        $endDate = $request->getQuery('end_date');
        $canchaId = $request->getQuery('cancha_id');
        $metodoPago = $request->getQuery('metodo_pago');

        $viewModel = new ViewModel([
            'sistema' => $sistema,
            'deporte' => $deporte,
            'evento' => null,
            'eventos' => [],
            'ultimoAjuste' => null,
            'paises' => [],
            'transacciones' => $this->loadSampleTransacciones($startDate, $endDate, $canchaId, $metodoPago),
            'resumen' => $this->loadResumenFinanciero($startDate, $endDate),
        ]);

        return $viewModel;
    }

    public function dashboardDataAction()
    {
        $request = $this->getRequest();
        $startDate = $request->getQuery('start_date');
        $endDate = $request->getQuery('end_date');
        $canchaId = $request->getQuery('cancha_id');
        $metodoPago = $request->getQuery('metodo_pago');

        return new JsonModel([
            'resumen' => $this->loadResumenFinanciero($startDate, $endDate),
            'graficos' => $this->loadGraficosData($startDate, $endDate, $canchaId, $metodoPago),
            'transacciones' => $this->loadSampleTransacciones($startDate, $endDate, $canchaId, $metodoPago),
        ]);
    }

    public function dashboardExportAction()
    {
        $request = $this->getRequest();
        $startDate = $request->getQuery('start_date', date('Y-m-01'));
        $endDate = $request->getQuery('end_date', date('Y-m-d'));

        $rows = [];
        if ($this->tableExists('reservas')) {
            try {
                $rows = $this->dbQuery(
                    'SELECT
                        id,
                        fecha,
                        hora_inicio,
                        hora_fin,
                        reserva_cancha_id AS cancha_id,
                        cliente_nombre   AS cliente,
                        cliente_telefono  AS cliente_telefono,
                        total_pago       AS total,
                        metodo_pago,
                        es_pagado        AS pagado,
                        split_pago       AS split_personas,
                        created_at
                      FROM reservas
                      WHERE fecha BETWEEN ? AND ?
                      ORDER BY fecha DESC, hora_inicio DESC
                      LIMIT 5000',
                    [$startDate, $endDate]
                )->toArray();
            } catch (\Throwable $e) {
                $rows = [];
            }
        }

        $csv = "id;fecha;hora_inicio;hora_fin;cancha;cliente;cliente_telefono;total;metodo_pago;pagado;split_personas;created_at\n";
        foreach ($rows as $r) {
            $csv .= sprintf(
                "%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s\n",
                $r['id'] ?? '',
                $r['fecha'] ?? '',
                $r['hora_inicio'] ?? '',
                $r['hora_fin'] ?? '',
                $r['cancha_id'] ?? '',
                str_replace([';', '"', "\n"], ' ', $r['cliente'] ?? ''),
                $r['cliente_telefono'] ?? '',
                $r['total'] ?? 0,
                $r['metodo_pago'] ?? '',
                isset($r['pagado']) ? (int) $r['pagado'] : 0,
                $r['split_personas'] ?? 0,
                $r['created_at'] ?? '',
            );
        }

        $response = $this->getResponse();
        $response->setStatusCode(200);
        $response->getHeaders()->addHeaders([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="reservas_'.date('Ymd_His').'.csv"',
        ]);
        $response->setContent($csv);

        return $response;
    }

    // =================== ENDPOINTS FALTANTES ===================

    public function galeriaUploadAction()
    {
        $request = $this->getRequest();
        if (! $request->isPost()) {
            return new JsonModel(['success' => false, 'error' => 'Método no permitido']);
        }
        $files = $request->getFiles();
        if (! isset($files['image']) || $files['image']['error'] !== UPLOAD_ERR_OK) {
            return new JsonModel(['success' => false, 'error' => 'No se subió ningún archivo']);
        }
        $file = $files['image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4'];
        if (! in_array($ext, $allowed, true)) {
            return new JsonModel(['success' => false, 'error' => 'Tipo de archivo no permitido']);
        }
        $uuid = Uuid::uuid4()->toString();
        $filename = $uuid.'.'.$ext;
        $uploadDir = getcwd().'/public/uploads/galeria';
        if (! is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }
        $destino = $uploadDir.'/'.$filename;
        if (! move_uploaded_file($file['tmp_name'], $destino)) {
            return new JsonModel(['success' => false, 'error' => 'No se pudo guardar el archivo']);
        }
        $publicPath = '/uploads/galeria/'.$filename;
        $imagen = [
            'url' => $publicPath,
            'filename' => $filename,
            'fecha_subida' => date('Y-m-d H:i:s'),
        ];

        // Actualizar el array galeria_config en BD
        $this->appendGaleriaConfig($imagen);

        return new JsonModel([
            'success' => true,
            'image' => $imagen,
        ]);
    }

    public function galeriaDeleteAction()
    {
        $request = $this->getRequest();
        $filename = $request->getQuery('filename', $request->getPost('filename', ''));
        if (empty($filename)) {
            return new JsonModel(['success' => false, 'error' => 'Falta filename']);
        }
        $safe = basename($filename);
        $filePath = getcwd().'/public/uploads/galeria/'.$safe;
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        $this->removeGaleriaConfig($safe);

        return new JsonModel(['success' => true]);
    }

    public function qrGenerateAction()
    {
        $request = $this->getRequest();
        $body = json_decode($request->getContent(), true) ?: $request->getPost()->toArray();
        $url = (string) ($body['url'] ?? '');
        if (empty($url)) {
            return new JsonModel(['success' => false, 'error' => 'URL vacía']);
        }
        // QR simple via Google Charts API (compatible sin librerías externas)
        $qr = 'https://chart.googleapis.com/chart?cht=qr&chs=200x200&chl='.urlencode($url).'&choe=UTF-8';

        return new JsonModel([
            'success' => true,
            'qr' => $qr,
        ]);
    }

    public function permisosSaveAction()
    {
        $request = $this->getRequest();
        $body = json_decode($request->getContent(), true) ?: $request->getPost()->toArray();
        $moderadores = $body['moderadores'] ?? [];
        if (! is_array($moderadores)) {
            $moderadores = [];
        }
        $userId = $this->auth->getUserId();
        if (! $userId) {
            return new JsonModel(['success' => false, 'error' => 'No autenticado']);
        }
        $json = json_encode(array_values($moderadores), JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '[]';
        }
        $this->upsertSistemaConfig($userId, 'moderadores_permitidos', $json);

        return new JsonModel(['success' => true]);
    }

    public function storeAction()
    {
        // Alias de principalAction con POST
        return $this->principalAction();
    }

    // =================== HELPERS PRIVADOS ===================

    private function dbQuery(string $sql, array $params = []): array
    {
        try {
            $rows = $this->db->getDriver()->getConnection()->execute($sql, $params);
            if ($rows instanceof \Laminas\Db\ResultSet\ResultSet) {
                return $rows->toArray();
            }
            if ($rows instanceof \Laminas\Db\Adapter\Driver\StatementInterface) {
                $out = [];
                foreach ($rows as $r) {
                    $out[] = is_array($r) ? $r : (array) $r;
                }
                return $out;
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    private function dbCurrent(string $sql, array $params = []): ?array
    {
        $rows = $this->dbQuery($sql, $params);
        return $rows[0] ?? null;
    }

    private function dbExecute(string $sql, array $params = []): bool
    {
        try {
            $this->db->getDriver()->getConnection()->execute($sql, $params);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    private function loadSistemaForCurrentUser(): \stdClass
    {
        $userId = $this->auth->getUserId();
        if (! $userId) {
            return $this->emptySistema();
        }

        if (! $this->tableExists('reserva_sistemas')) {
            return $this->emptySistema();
        }

        try {
            $row = $this->dbQuery(
                'SELECT * FROM reserva_sistemas WHERE user_id = ? LIMIT 1',
                [$userId]
            )->current();
        } catch (\Throwable $e) {
            $row = null;
        }
        if (! $row) {
            return $this->emptySistema();
        }
        $sistema = (object) (array) $row;
        $sistema->amenities_config = $this->decodeJson($sistema->amenities_config ?? '[]');
        $sistema->extras_config = $this->decodeJson($sistema->extras_config ?? '[]');
        $sistema->cupones_config = $this->decodeJson($sistema->cupones_config ?? '[]');
        $sistema->galeria_config = $this->decodeJson($sistema->galeria_config ?? '[]');
        $sistema->moderadores_permitidos = $this->decodeJson($sistema->moderadores_permitidos ?? '[]');

        if ($this->tableExists('reserva_canchas')) {
            try {
                $rows = $this->dbQuery(
                    'SELECT * FROM reserva_canchas WHERE reserva_sistema_id = ?',
                    [$sistema->id]
                )->toArray();
            } catch (\Throwable $e) {
                $rows = [];
            }
            $sistema->canchas = array_map(fn ($r) => (object) $r, $rows);
        } else {
            $sistema->canchas = [];
        }

        return $sistema;
    }

    private function emptySistema(): \stdClass
    {
        $s = new \stdClass;
        $s->id = null;
        $s->user_id = null;
        $s->slug = '';
        $s->nombre_negocio = '';
        $s->is_active = 0;
        $s->moneda = 'CLP';
        $s->intervalo_min = 60;
        $s->hora_inicio = '08:00';
        $s->hora_fin = '22:00';
        $s->precio_base = '';
        $s->max_div_pago = 1;
        $s->uuid = null;
        $s->amenities_config = [];
        $s->extras_config = [];
        $s->cupones_config = [];
        $s->galeria_config = [];
        $s->moderadores_permitidos = [];
        $s->canchas = [];

        return $s;
    }

    private function decodeJson($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function loadResumenFinanciero(?string $startDate = null, ?string $endDate = null): array
    {
        $empty = [
            'ingresos_hoy' => 0, 'ingresos_semana' => 0,
            'ingresos_mes' => 0, 'ingresos_anio' => 0,
        ];
        if (! $this->tableExists('reservas')) {
            return $empty;
        }
        $whereDate = $this->buildDateFilter($startDate, $endDate);
        try {
            $row = $this->dbQuery(
                "SELECT
                    SUM(CASE WHEN DATE(fecha) = CURDATE() AND es_pagado = 1 THEN total_pago ELSE 0 END) AS hoy,
                    SUM(CASE WHEN fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND es_pagado = 1 THEN total_pago ELSE 0 END) AS semana,
                    SUM(CASE WHEN DATE_FORMAT(fecha, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND es_pagado = 1 THEN total_pago ELSE 0 END) AS mes,
                    SUM(CASE WHEN YEAR(fecha) = YEAR(CURDATE()) AND es_pagado = 1 THEN total_pago ELSE 0 END) AS anio
                 FROM reservas WHERE 1=1 $whereDate"
            )->current();
        } catch (\Throwable $e) {
            return $empty;
        }

        return [
            'ingresos_hoy' => (float) ($row['hoy'] ?? 0),
            'ingresos_semana' => (float) ($row['semana'] ?? 0),
            'ingresos_mes' => (float) ($row['mes'] ?? 0),
            'ingresos_anio' => (float) ($row['anio'] ?? 0),
        ];
    }

    private function loadGraficosData(?string $startDate = null, ?string $endDate = null, ?string $canchaId = null, ?string $metodoPago = null): array
    {
        $empty = [
            'ingresos_por_dia' => [],
            'ingresos_por_metodo' => [],
            'ingresos_por_cancha' => [],
            'top_extras' => [],
            'horarios_rentables' => [],
        ];
        if (! $this->tableExists('reservas')) {
            return $empty;
        }

        $whereDate = $this->buildDateFilter($startDate, $endDate);
        $whereCancha = $canchaId ? ' AND reserva_cancha_id = '.(int) $canchaId : '';
        $whereMetodo = $metodoPago ? ' AND metodo_pago = '.$this->db->getDriver()->getConnection()->quote($metodoPago) : '';

        try {
            // Ingresos por día
            $rowsDia = $this->dbQuery(
                "SELECT DATE(fecha) AS fecha, COALESCE(SUM(total_pago), 0) AS total
                   FROM reservas
                   WHERE es_pagado = 1 $whereDate $whereCancha $whereMetodo
                   GROUP BY DATE(fecha)
                   ORDER BY fecha ASC
                   LIMIT 90"
            )->toArray();
            $ingresosPorDia = array_map(fn ($r) => [
                'fecha' => (string) $r['fecha'],
                'total' => (float) $r['total'],
            ], $rowsDia);

            // Ingresos por método de pago
            $rowsMet = $this->dbQuery(
                "SELECT metodo_pago, COALESCE(SUM(total_pago), 0) AS total
                   FROM reservas
                   WHERE es_pagado = 1 $whereDate $whereCancha
                   GROUP BY metodo_pago"
            )->toArray();
            $ingresosPorMetodo = [];
            foreach ($rowsMet as $r) {
                $ingresosPorMetodo[(string) ($r['metodo_pago'] ?? 'N/D')] = (float) $r['total'];
            }

            // Ingresos por cancha (si existe la tabla reserva_canchas)
            $ingresosPorCancha = [];
            if ($this->tableExists('reserva_canchas')) {
                $rowsCancha = $this->dbQuery(
                    "SELECT rc.id, rc.nombre, COALESCE(SUM(r.total_pago), 0) AS total
                       FROM reservas r
                       INNER JOIN reserva_canchas rc ON rc.id = r.reserva_cancha_id
                       WHERE r.es_pagado = 1 $whereDate
                       GROUP BY rc.id, rc.nombre
                       ORDER BY total DESC
                       LIMIT 10"
                )->toArray();
                $ingresosPorCancha = array_map(fn ($r) => [
                    'cancha' => (string) $r['nombre'],
                    'total' => (float) $r['total'],
                ], $rowsCancha);
            }

            return [
                'ingresos_por_dia' => $ingresosPorDia,
                'ingresos_por_metodo' => $ingresosPorMetodo,
                'ingresos_por_cancha' => $ingresosPorCancha,
                'top_extras' => [],
                'horarios_rentables' => [],
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    private function loadSampleTransacciones(?string $startDate = null, ?string $endDate = null, ?string $canchaId = null, ?string $metodoPago = null): array
    {
        if (! $this->tableExists('reservas')) {
            return [];
        }
        $whereDate = $this->buildDateFilter($startDate, $endDate);
        $whereCancha = $canchaId ? ' AND reserva_cancha_id = '.(int) $canchaId : '';
        $whereMetodo = $metodoPago ? ' AND metodo_pago = '.$this->db->getDriver()->getConnection()->quote($metodoPago) : '';
        try {
            $rows = $this->dbQuery(
                "SELECT id, fecha, hora_inicio, hora_fin, reserva_cancha_id AS cancha_id,
                        cliente_nombre, total_pago, metodo_pago, es_pagado, split_pago
                   FROM reservas
                   WHERE 1=1 $whereDate $whereCancha $whereMetodo
                   ORDER BY fecha DESC, hora_inicio DESC
                   LIMIT 100"
            )->toArray();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'fecha' => (string) ($r['fecha'] ?? ''),
                'hora' => ($r['hora_inicio'] ?? '').' - '.($r['hora_fin'] ?? ''),
                'cancha' => (string) ($r['cancha_id'] ?? ''),
                'cliente' => (string) ($r['cliente_nombre'] ?? ''),
                'total' => (float) ($r['total_pago'] ?? 0),
                'metodo' => (string) ($r['metodo_pago'] ?? ''),
                'pagado' => (int) ($r['es_pagado'] ?? 0),
                'split' => (int) ($r['split_pago'] ?? 0),
            ];
        }

        return $out;
    }

    private function buildDateFilter(?string $startDate, ?string $endDate): string
    {
        $where = '';
        if ($startDate) {
            $where .= ' AND fecha >= '.$this->db->getDriver()->getConnection()->quote($startDate);
        }
        if ($endDate) {
            $where .= ' AND fecha <= '.$this->db->getDriver()->getConnection()->quote($endDate);
        }

        return $where;
    }

    private function tableExists(string $table): bool
    {
        try {
            $row = $this->dbQuery('SHOW TABLES LIKE ?', [$table])->current();

            return (bool) $row;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function saveSistema(\stdClass $sistema, array $post): ViewModel
    {
        $userId = $this->auth->getUserId();
        if (! $userId) {
            return new ViewModel(['error' => 'No autenticado']);
        }

        $data = [
            'user_id' => $userId,
            'slug' => $post['slug'] ?? '',
            'nombre_negocio' => $post['nombre_negocio'] ?? '',
            'is_active' => isset($post['is_active']) ? 1 : 0,
            'moneda' => $post['moneda'] ?? 'CLP',
            'intervalo_min' => (int) ($post['intervalo_min'] ?? 60),
            'hora_inicio' => $post['hora_inicio'] ?? '08:00',
            'hora_fin' => $post['hora_fin'] ?? '22:00',
            'precio_base' => $post['precio_base'] ?? '',
            'max_div_pago' => (int) ($post['max_div_pago'] ?? 1),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Serializar configs JSON
        // El form envía los campos como amenities_config[]/extras_config[]/cupones_config[]
        // (ver principal.phtml); leerlos como 'amenities'/'extras'/'cupones' siempre
        // devolvía vacío y el guardado de estas 3 secciones nunca se aplicaba.
        $amenities = json_encode($this->parseConfigRows($post['amenities_config'] ?? []));
        $extras = json_encode($this->parseConfigRows($post['extras_config'] ?? []));
        $cupones = json_encode($this->parseCupones($post['cupones_config'] ?? []));
        $galeria = json_encode($this->decodeJson($sistema->galeria_config ?? '[]'));
        $modPer = json_encode(array_values(array_filter((array) ($post['moderadores'] ?? []))));

        if (! $this->tableExists('reserva_sistemas')) {
            return new ViewModel(['error' => 'La tabla reserva_sistemas no existe. Ejecuta migraciones.']);
        }

        try {
            if ($sistema->id) {
                $this->dbQuery(
                    'UPDATE reserva_sistemas SET
                        slug = ?, nombre_negocio = ?, is_active = ?, moneda = ?,
                        intervalo_min = ?, hora_inicio = ?, hora_fin = ?, precio_base = ?,
                        max_div_pago = ?, amenities_config = ?, extras_config = ?, cupones_config = ?,
                        galeria_config = ?, moderadores_permitidos = ?, updated_at = ?
                      WHERE id = ? AND user_id = ?',
                    [
                        $data['slug'], $data['nombre_negocio'], $data['is_active'], $data['moneda'],
                        $data['intervalo_min'], $data['hora_inicio'], $data['hora_fin'], $data['precio_base'],
                        $data['max_div_pago'], $amenities, $extras, $cupones, $galeria, $modPer,
                        $data['updated_at'], $sistema->id, $userId,
                    ]
                );
                $sistemaId = $sistema->id;
            } else {
                $uuid = Uuid::uuid4()->toString();
                $data['uuid'] = $uuid;
                $data['created_at'] = date('Y-m-d H:i:s');
                $this->dbQuery(
                    'INSERT INTO reserva_sistemas
                       (uuid, user_id, slug, nombre_negocio, is_active, moneda,
                        intervalo_min, hora_inicio, hora_fin, precio_base, max_div_pago,
                        amenities_config, extras_config, cupones_config,
                        galeria_config, moderadores_permitidos, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $uuid, $userId, $data['slug'], $data['nombre_negocio'], $data['is_active'], $data['moneda'],
                        $data['intervalo_min'], $data['hora_inicio'], $data['hora_fin'], $data['precio_base'], $data['max_div_pago'],
                        $amenities, $extras, $cupones, $galeria, $modPer, $data['created_at'], $data['updated_at'],
                    ]
                );
                $sistemaId = (int) $this->db->getDriver()->getLastGeneratedValue();
            }
        } catch (\Throwable $e) {
            return new ViewModel(['error' => 'Error al guardar: '.$e->getMessage()]);
        }

        // Guardar canchas
        if ($this->tableExists('reserva_canchas')) {
            $canchas = $this->parseCanchas($post['canchas'] ?? []);
            $currentIds = [];
            foreach ($this->dbQuery(
                'SELECT id FROM reserva_canchas WHERE reserva_sistema_id = ?',
                [$sistemaId]
            )->toArray() as $row) {
                $currentIds[] = (int) $row['id'];
            }
            $postedIds = [];
            foreach ($canchas as $c) {
                $nombre = trim((string) ($c['nombre'] ?? ''));
                $tipo = (string) ($c['tipo'] ?? 'Standard');
                $cant = max(1, (int) ($c['cantidad'] ?? 1));
                $precio = (float) ($c['precio'] ?? 0);
                $desc = (string) ($c['descripcion'] ?? '');
                $horario = (string) ($c['horario'] ?? '08:00 - 22:00');
                if ($nombre === '') {
                    continue;
                }
                if (! empty($c['id']) && in_array((int) $c['id'], $currentIds, true)) {
                    $this->dbQuery(
                        'UPDATE reserva_canchas SET
                            nombre=?, tipo=?, cantidad=?, precio=?, descripcion=?, horario=?
                          WHERE id=? AND reserva_sistema_id=?',
                        [$nombre, $tipo, $cant, $precio, $desc, $horario, (int) $c['id'], $sistemaId]
                    );
                    $postedIds[] = (int) $c['id'];
                } else {
                    $this->dbQuery(
                        'INSERT INTO reserva_canchas
                           (reserva_sistema_id, nombre, tipo, cantidad, precio, descripcion, horario)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$sistemaId, $nombre, $tipo, $cant, $precio, $desc, $horario]
                    );
                    $postedIds[] = (int) $this->db->getDriver()->getLastGeneratedValue();
                }
            }
            // Borrar canchas que ya no están
            foreach ($currentIds as $cid) {
                if (! in_array($cid, $postedIds, true)) {
                    $this->dbQuery('DELETE FROM reserva_canchas WHERE id = ?', [$cid]);
                }
            }
        }

        $_SESSION['mavoo_flash'][] = ['type' => 'success', 'message' => 'Configuración guardada correctamente'];

        return $this->redirect()->toUrl($this->url()->fromRoute('reservas'));
    }

    private function parseConfigRows($rows): array
    {
        $result = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $clean = [];
                foreach ($row as $k => $v) {
                    $clean[$k] = is_string($v) ? trim($v) : $v;
                }
                if (empty($clean['nombre']) && empty($clean['icono']) && empty($clean['codigo'])) {
                    continue;
                }
                $result[] = $clean;
            }
        }

        return $result;
    }

    private function parseCupones($rows): array
    {
        $result = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $clean = [];
                foreach ($row as $k => $v) {
                    $clean[$k] = is_string($v) ? trim($v) : $v;
                }
                $clean['is_active'] = ! empty($clean['is_active']) ? 1 : 0;
                if (empty($clean['code'])) {
                    continue;
                }
                $result[] = $clean;
            }
        }

        return $result;
    }

    private function parseCanchas($rows): array
    {
        $result = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $clean = [];
                foreach ($row as $k => $v) {
                    $clean[$k] = is_string($v) ? trim($v) : $v;
                }
                if (empty($clean['nombre'])) {
                    continue;
                }
                $result[] = $clean;
            }
        }

        return $result;
    }

    private function appendGaleriaConfig(array $imagen): void
    {
        $userId = $this->auth->getUserId();
        if (! $userId || ! $this->tableExists('reserva_sistemas')) {
            return;
        }
        try {
            $row = $this->dbQuery(
                'SELECT id, galeria_config FROM reserva_sistemas WHERE user_id = ? LIMIT 1',
                [$userId]
            )->current();
        } catch (\Throwable $e) {
            return;
        }
        if (! $row) {
            return;
        }
        $galeria = $this->decodeJson($row['galeria_config'] ?? '[]');
        $galeria[] = $imagen;
        $this->dbQuery(
            'UPDATE reserva_sistemas SET galeria_config = ?, updated_at = NOW() WHERE id = ?',
            [json_encode($galeria, JSON_UNESCAPED_UNICODE), $row['id']]
        );
    }

    private function removeGaleriaConfig(string $filename): void
    {
        $userId = $this->auth->getUserId();
        if (! $userId || ! $this->tableExists('reserva_sistemas')) {
            return;
        }
        try {
            $row = $this->dbQuery(
                'SELECT id, galeria_config FROM reserva_sistemas WHERE user_id = ? LIMIT 1',
                [$userId]
            )->current();
        } catch (\Throwable $e) {
            return;
        }
        if (! $row) {
            return;
        }
        $galeria = array_values(array_filter(
            $this->decodeJson($row['galeria_config'] ?? '[]'),
            fn ($i) => ($i['filename'] ?? '') !== $filename
        ));
        $this->dbQuery(
            'UPDATE reserva_sistemas SET galeria_config = ?, updated_at = NOW() WHERE id = ?',
            [json_encode($galeria, JSON_UNESCAPED_UNICODE), $row['id']]
        );
    }

    private function upsertSistemaConfig(int $userId, string $field, string $value): void
    {
        if (! $this->tableExists('reserva_sistemas')) {
            return;
        }
        try {
            $row = $this->dbQuery(
                'SELECT id FROM reserva_sistemas WHERE user_id = ? LIMIT 1',
                [$userId]
            )->current();
        } catch (\Throwable $e) {
            return;
        }
        $allowed = ['slug', 'nombre_negocio', 'moneda', 'amenities_config', 'extras_config',
            'cupones_config', 'galeria_config', 'moderadores_permitidos'];
        if (! in_array($field, $allowed, true)) {
            return;
        }
        if ($row) {
            $this->dbQuery(
                "UPDATE reserva_sistemas SET $field = ?, updated_at = NOW() WHERE id = ?",
                [$value, $row['id']]
            );
        } else {
            $uuid = Uuid::uuid4()->toString();
            $this->dbQuery(
                "INSERT INTO reserva_sistemas
                   (uuid, user_id, $field, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())",
                [$uuid, $userId, $value]
            );
        }
    }
}
