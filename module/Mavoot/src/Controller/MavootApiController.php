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

    // Endpoints for Worker (to be fully fleshed out later)
    public function pendingJobsAction()
    {
        return new JsonModel(['success' => true, 'jobs' => []]);
    }

    public function claimAction()
    {
        return new JsonModel(['success' => true, 'message' => 'Job reclamado']);
    }

    public function contextAction()
    {
        return new JsonModel(['success' => true, 'context' => []]);
    }

    public function saveResponseAction()
    {
        return new JsonModel(['success' => true, 'message' => 'Respuesta guardada.']);
    }
}

