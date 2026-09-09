<?php

namespace Mavoot\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Mavoot\Model\MavootSessionTable;
use Mavoot\Model\MavootMessageTable;

class MavootApiController extends AbstractActionController
{
    private $sessionTable;
    private $messageTable;

    public function __construct(MavootSessionTable $sessionTable, MavootMessageTable $messageTable) 
    {
        $this->sessionTable = $sessionTable;
        $this->messageTable = $messageTable;
    }

    public function sendMessageAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'error' => 'Method not allowed']);
        }

        try {
            $data = json_decode($request->getContent(), true) ?: $request->getPost()->toArray();
            $userId = (int) ($data['user_id'] ?? 0);
            $text = trim($data['message'] ?? '');

            if (!$userId || empty($text)) {
                return new JsonModel(['success' => false, 'error' => 'user_id y message son requeridos']);
            }

            // Buscar sesión activa de menos de 10 min
            $session = $this->sessionTable->getActiveSession($userId);
            if (!$session) {
                $sessionId = $this->sessionTable->createSession($userId);
            } else {
                $sessionId = $session->id;
                $this->sessionTable->updateSessionActivity($sessionId);
            }

            // Guardar el mensaje del usuario como pending
            $messageId = $this->messageTable->createMessage($sessionId, 'user', $text, 'pending');

            return new JsonModel([
                'success' => true,
                'session_id' => $sessionId,
                'message_id' => $messageId
            ]);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function latestResponseAction()
    {
        $sessionId = (int) $this->params()->fromQuery('session_id', 0);
        $lastMessageId = (int) $this->params()->fromQuery('last_message_id', 0);

        if (!$sessionId) {
            return new JsonModel(['success' => false, 'error' => 'session_id requerido']);
        }

        try {
            $messages = $this->messageTable->getLatestResponses($sessionId, $lastMessageId);
            
            return new JsonModel([
                'success' => true,
                'messages' => $messages,
                'has_new' => count($messages) > 0,
                'latest_id' => count($messages) > 0 ? end($messages)['id'] : $lastMessageId
            ]);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/mavoot/pending-jobs
     * El Worker de IA consulta mensajes de usuario en estado 'pending'.
     */
    public function pendingJobsAction()
    {
        try {
            $jobs = $this->messageTable->getPendingMessages(20);

            return new JsonModel(['success' => true, 'jobs' => $jobs]);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * PATCH /api/mavoot/:id/claim
     * El Worker reclama un mensaje pendiente para procesarlo (evita colisiones
     * si hay 2+ workers concurrentes).
     */
    public function claimAction()
    {
        $messageId = (int) $this->params()->fromRoute('id', 0);
        $data = json_decode($this->getRequest()->getContent(), true) ?: [];
        $workerId = (string) ($data['worker_id'] ?? uniqid('worker_', true));

        if (! $messageId) {
            return new JsonModel(['success' => false, 'error' => 'id de mensaje requerido']);
        }

        try {
            $claimed = $this->messageTable->claimMessage($messageId, $workerId);
            if (! $claimed) {
                return new JsonModel(['success' => false, 'error' => 'El mensaje no existe o ya fue reclamado']);
            }

            return new JsonModel(['success' => true, 'message' => 'Job reclamado']);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/mavoot/context/:session_id
     * El Worker lee todo el historial de una sesión para contextualizar el prompt.
     */
    public function contextAction()
    {
        $sessionId = (int) $this->params()->fromRoute('session_id', 0);
        if (! $sessionId) {
            return new JsonModel(['success' => false, 'error' => 'session_id requerido']);
        }

        try {
            $context = $this->messageTable->getSessionMessages($sessionId);

            return new JsonModel(['success' => true, 'context' => $context]);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * POST /api/mavoot/response
     * El Worker guarda la respuesta final de la IA. Ver documentacion_mavoot/mavoot.md
     * sección 3 para la lógica del límite de 10 respuestas.
     *
     * Nota: la comprobación de "agentes de soporte disponibles" del pseudocódigo
     * original no existe todavía como servicio (no hay tracking de usuarios
     * online). Aquí se usa un proxy simple: si existe al menos un usuario con
     * rol de soporte (moderador/admin/ceo/dios), se transfiere a humano;
     * si no, se cierra la sesión. Ajustar cuando exista un servicio real de
     * disponibilidad de agentes.
     */
    public function saveResponseAction()
    {
        $request = $this->getRequest();
        if (! $request->isPost()) {
            return new JsonModel(['success' => false, 'error' => 'Method not allowed']);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $originalMessageId = (int) ($data['original_message_id'] ?? 0);
        $sessionId = (int) ($data['session_id'] ?? 0);
        $responseText = trim((string) ($data['response'] ?? ''));

        if (! $originalMessageId || ! $sessionId || $responseText === '') {
            return new JsonModel(['success' => false, 'error' => 'original_message_id, session_id y response son requeridos']);
        }

        try {
            // 1. Marca el mensaje original como resuelto.
            $this->messageTable->markDone($originalMessageId);

            // 2. Crea el mensaje de respuesta del bot.
            $responseMessageId = $this->messageTable->createMessage($sessionId, 'mavoot', $responseText, 'done');

            // 3. Incrementa el contador de respuestas de la sesión y evalúa el límite.
            $count = $this->sessionTable->incrementBotResponses($sessionId);

            if ($count >= 10) {
                $hayAgentesDisponibles = $this->haySoporteDisponible();

                if ($hayAgentesDisponibles) {
                    $this->sessionTable->updateStatus($sessionId, 'transferred_to_human');
                    $this->messageTable->createSystemMessage(
                        $sessionId,
                        'Se ha excedido la lógica del bot. Un humano está tomando el control. Por favor aguarde...'
                    );
                } else {
                    $this->sessionTable->updateStatus($sessionId, 'closed');
                    $this->messageTable->createSystemMessage(
                        $sessionId,
                        'Agradecemos tu interés, pero hemos excedido el límite de esta consulta. '
                        .'Envía un correo a través de nuestro formulario en https://mavoo.fit/contacto'
                    );
                }
            }

            return new JsonModel([
                'success' => true,
                'message' => 'Respuesta guardada.',
                'response_message_id' => $responseMessageId,
                'bot_responses_count' => $count,
            ]);
        } catch (\Exception $e) {
            return new JsonModel(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Proxy simple de "hay soporte disponible": existe al menos un usuario
     * con rol de moderación/administración en el sistema. No verifica
     * presencia/online real (no existe esa infraestructura hoy).
     */
    private function haySoporteDisponible(): bool
    {
        $adapter = \Mavoot\Model\MavooDb::getAdapter();
        if (! $adapter) {
            return false;
        }

        try {
            $result = $adapter->query(
                "SELECT COUNT(*) AS c FROM model_has_roles mhr
                 INNER JOIN roles r ON r.id = mhr.role_id
                 WHERE r.name IN ('moderador', 'admin', 'ceo', 'dios')",
                []
            );
            $row = $result->current();

            return ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

